<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;

final class ImageInspectorTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/lf-media-inspector-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        if ('' === $this->directory || !is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testReadsImageHeadersFromAFile(): void
    {
        $path = $this->directory.'/pixel.png';
        file_put_contents($path, $this->png());

        $image = (new ImageInspector())->inspectFile($path);

        $this->assertSame('image/png', $image->mime);
        $this->assertSame('png', $image->extension);
        $this->assertSame(1, $image->width);
        $this->assertSame(1, $image->height);
    }

    public function testAcceptsGifAndWebpBytes(): void
    {
        $inspector = new ImageInspector();

        $gif = $inspector->inspectContents($this->gif());
        $this->assertSame('image/gif', $gif->mime);
        $this->assertSame('gif', $gif->extension);

        $webp = $inspector->inspectContents($this->webp());
        $this->assertSame('image/webp', $webp->mime);
        $this->assertSame('webp', $webp->extension);
    }

    public function testRejectsBytesThatAreNotAnImage(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_TYPE);

        (new ImageInspector())->inspectContents('not an image');
    }

    public function testRejectsAFileThatIsNotAnImage(): void
    {
        $path = $this->directory.'/notes.txt';
        file_put_contents($path, 'not an image');

        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_TYPE);

        (new ImageInspector())->inspectFile($path);
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }

    private function gif(): string
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        $this->assertIsString($gif);

        return $gif;
    }

    private function webp(): string
    {
        $webp = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=', true);
        $this->assertIsString($webp);

        return $webp;
    }
}
