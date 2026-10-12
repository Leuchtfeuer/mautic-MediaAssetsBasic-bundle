<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageLibrary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use PHPUnit\Framework\TestCase;

final class ImageLibraryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/lf-media-assets-'.uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $pathname = $file->getPathname();
            $file->isDir() ? rmdir($pathname) : unlink($pathname);
        }
        rmdir($this->directory);
    }

    public function testListsOnlyImagesInTheTopDirectoryNewestFirst(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $older   = $storage->store($this->directory, 'older.png', $this->png());
        $newer   = $storage->store($this->directory, 'newer.png', $this->png());
        touch($this->directory.'/'.$older->name, time() - 120);
        touch($this->directory.'/'.$newer->name, time());
        file_put_contents($this->directory.'/notes.txt', 'not an image');
        mkdir($this->directory.'/nested');
        file_put_contents($this->directory.'/nested/hidden.png', $this->png());

        $page = (new ImageLibrary(new ImageInspector()))->listImages($this->directory, 1, 24);

        $this->assertSame(2, $page['total']);
        $this->assertSame([$newer->name, $older->name], array_map(static fn (ListedImage $image): string => $image->name, $page['images']));
    }

    public function testReturnsOnlyTheRequestedPageOfValidImages(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $oldest  = $storage->store($this->directory, 'oldest.png', $this->png());
        $middle  = $storage->store($this->directory, 'middle.png', $this->png());
        $newest  = $storage->store($this->directory, 'newest.png', $this->png());
        touch($this->directory.'/'.$oldest->name, time() - 180);
        touch($this->directory.'/'.$middle->name, time() - 60);
        touch($this->directory.'/'.$newest->name, time());
        file_put_contents($this->directory.'/notes.txt', 'not an image');

        $library = new ImageLibrary(new ImageInspector());
        $first   = $library->listImages($this->directory, 1, 2);
        $second  = $library->listImages($this->directory, 2, 2);

        $this->assertSame(3, $first['total']);
        $this->assertSame([$newest->name, $middle->name], array_map(static fn (ListedImage $image): string => $image->name, $first['images']));
        $this->assertSame(3, $second['total']);
        $this->assertSame([$oldest->name], array_map(static fn (ListedImage $image): string => $image->name, $second['images']));
    }

    public function testRejectsAFileThatIsNotAnImage(): void
    {
        $this->expectException(ImageRejectedException::class);

        (new ImageStorage(new ImageInspector()))->store($this->directory, 'photo.jpg', 'not an image');
    }

    public function testListsSvgFilesOnlyWhenSvgIsAllowed(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $storage->store($this->directory, 'icon.svg', $this->svg(), '', true);
        $storage->store($this->directory, 'photo.png', $this->png());

        $library = new ImageLibrary(new ImageInspector());
        $without = $library->listImages($this->directory, 1, 24, '', false);
        $with    = $library->listImages($this->directory, 1, 24, '', true);

        $this->assertSame(['photo.png'], array_map(static fn (ListedImage $image): string => $image->name, $without['images']));
        $this->assertSame(1, $without['total']);
        $this->assertEqualsCanonicalizing(
            ['photo.png', 'icon.svg'],
            array_map(static fn (ListedImage $image): string => $image->name, $with['images']),
        );
        $this->assertSame(2, $with['total']);
    }

    private function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="8"><rect width="12" height="8" fill="#000"/></svg>';
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }
}
