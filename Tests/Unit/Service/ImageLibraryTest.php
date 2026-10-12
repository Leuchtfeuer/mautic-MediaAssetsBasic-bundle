<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageLibrary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ImageLibraryTest extends TestCase
{
    /** @var MediaAssetFileRepository&MockObject */
    private MockObject $files;

    protected function setUp(): void
    {
        $this->files = $this->createMock(MediaAssetFileRepository::class);
    }

    public function testListsImagesFromTheRepository(): void
    {
        $newer = $this->file('newer.png', 'newer.png', 2, 2, 100, 200);
        $older = $this->file('older.png', 'older.png', 2, 2, 100, 100);

        $this->files->expects($this->once())
            ->method('listInFolder')
            ->with('', 1, 24, false)
            ->willReturn(['images' => [$newer, $older], 'total' => 2]);

        $page = (new ImageLibrary($this->files))->listImages('', 1, 24);

        $this->assertSame(2, $page['total']);
        $this->assertSame(['newer.png', 'older.png'], array_map(static fn (\MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage $image) => $image->name, $page['images']));
    }

    public function testPassesAllowSvgToTheRepository(): void
    {
        $this->files->expects($this->once())
            ->method('listInFolder')
            ->with('Brand', 2, 10, true)
            ->willReturn(['images' => [], 'total' => 0]);

        (new ImageLibrary($this->files))->listImages('Brand', 2, 10, true);
    }

    private function file(string $filename, string $relativePath, int $width, int $height, int $bytes, int $mtime): MediaAssetFile
    {
        $file = new MediaAssetFile();
        $file->setFilename($filename)
            ->setRelativePath($relativePath)
            ->setMime(str_ends_with($filename, '.svg') ? ImageInspector::SVG_MIME : 'image/png')
            ->setWidth($width)
            ->setHeight($height)
            ->setBytes($bytes)
            ->setFileMtime($mtime);

        return $file;
    }
}
