<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

        $this->removeDirectory($this->directory);
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
        $stored = (new ImageStorage(new ImageInspector()))->store($this->directory, '---------------------.jpg', $this->png());

        $this->assertSame('image.png', $stored->name);
    }

    public function testMovesAnUploadedFileIntoTheDirectory(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'lf-upload');
        $this->assertIsString($source);
        file_put_contents($source, $this->png());
        $file = new UploadedFile($source, 'Hero Image (final).png', 'image/png', null, true);

        $stored = (new ImageStorage(new ImageInspector()))->storeUploaded($this->directory, $file);

        $this->assertSame('Hero-Image-final.png', $stored->name);
        $this->assertSame(1, $stored->width);
        $this->assertFileExists($this->directory.'/Hero-Image-final.png');
        $this->assertFileDoesNotExist($source);
    }

    public function testRejectsContentsThatAreNotAnImage(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_TYPE);

        (new ImageStorage(new ImageInspector()))->store($this->directory, 'notes.txt', 'not an image');
    }

    public function testMovesAFileIntoAnotherFolder(): void
    {
        mkdir($this->directory.'/Target', 0775, true);
        file_put_contents($this->directory.'/hero.png', $this->png());

        $storage = new ImageStorage(new ImageInspector());
        $moved   = $storage->move($this->paths(), 'hero.png', 'Target');

        $this->assertSame('hero.png', $moved->name);
        $this->assertSame('Target/hero.png', $moved->path);
        $this->assertFileDoesNotExist($this->directory.'/hero.png');
        $this->assertFileExists($this->directory.'/Target/hero.png');
    }

    public function testMovePicksAUniqueNameWhenTheTargetAlreadyContainsTheFile(): void
    {
        mkdir($this->directory.'/Target', 0775, true);
        file_put_contents($this->directory.'/Target/hero.png', $this->png());
        file_put_contents($this->directory.'/hero.png', $this->png());

        $moved = (new ImageStorage(new ImageInspector()))->move($this->paths(), 'hero.png', 'Target');

        $this->assertSame('hero-1.png', $moved->name);
        $this->assertSame('Target/hero-1.png', $moved->path);
        $this->assertFileExists($this->directory.'/Target/hero.png');
        $this->assertFileExists($this->directory.'/Target/hero-1.png');
    }

    public function testMoveRejectsTheSameParentFolder(): void
    {
        file_put_contents($this->directory.'/hero.png', $this->png());

        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_PATH);

        (new ImageStorage(new ImageInspector()))->move($this->paths(), 'hero.png', '');
    }

    private function paths(): MediaPath
    {
        $helper = $this->createMock(PathsHelper::class);
        $helper->method('getImagePath')->willReturn($this->directory.'/');
        $helper->method('getSystemPath')->willReturn('media/images');

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('');

        return new MediaPath(new ImageUrls($helper, $parameters));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }
            unlink($path);
        }
        rmdir($directory);
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }
}
