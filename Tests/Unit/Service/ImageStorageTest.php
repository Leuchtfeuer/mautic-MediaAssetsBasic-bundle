<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;

final class ImageStorageTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/lf-media-storage-'.bin2hex(random_bytes(4));
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

    public function testStoresASanitizedUniqueName(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $png     = $this->png();

        $first  = $storage->store($this->directory, 'Hero Image (final).png', $png);
        $second = $storage->store($this->directory, 'Hero Image (final).png', $png);

        $this->assertSame('Hero-Image-final.png', $first->name);
        $this->assertSame('Hero-Image-final-1.png', $second->name);
        $this->assertSame(1, $first->width);
        $this->assertSame(strlen($png), $first->bytes);
        $this->assertFileExists($this->directory.'/'.$second->name);
    }

    public function testUsesAFallbackStemWhenTheNameHasNoSafeCharacters(): void
    {
        $stored = (new ImageStorage(new ImageInspector()))->store($this->directory, '@@@.png', $this->png());

        $this->assertSame('image.png', $stored->name);
    }

    public function testRejectsContentsThatAreNotAnImage(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_TYPE);

        (new ImageStorage(new ImageInspector()))->store($this->directory, 'notes.txt', 'not an image');
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }
}
