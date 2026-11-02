<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionState;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaRenditionRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageLibrary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ImageLibraryTest extends TestCase
{
    /** @var MediaAssetFileRepository&MockObject */
    private MockObject $files;

    /** @var MediaRenditionRepository&MockObject */
    private MockObject $renditions;

    protected function setUp(): void
    {
        $this->files      = $this->createMock(MediaAssetFileRepository::class);
        $this->renditions = $this->createMock(MediaRenditionRepository::class);
    }

    private function library(): ImageLibrary
    {
        return new ImageLibrary($this->files, $this->renditions);
    }

    public function testListsImagesFromTheRepository(): void
    {
        $newer = $this->file('newer.png', 'newer.png', 2, 2, 100, 200);
        $older = $this->file('older.png', 'older.png', 2, 2, 100, 100);

        $this->files->expects($this->once())
            ->method('listInFolder')
            ->with('', 1, 24, false)
            ->willReturn(['images' => [$newer, $older], 'total' => 2]);
        $this->renditions->method('mapReadyByFileIds')->willReturn([]);

        $page = $this->library()->listImages('', 1, 24);

        $this->assertSame(2, $page['total']);
        $this->assertSame(['newer.png', 'older.png'], array_map(static fn (\MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage $image): string => $image->name, $page['images']));
    }

    public function testPassesAllowSvgToTheRepository(): void
    {
        $this->files->expects($this->once())
            ->method('listInFolder')
            ->with('Brand', 2, 10, true)
            ->willReturn(['images' => [], 'total' => 0]);

        $this->library()->listImages('Brand', 2, 10, true);
    }

    public function testShortQueryUsesFolderListing(): void
    {
        $this->files->expects($this->once())
            ->method('listInFolder')
            ->with('Campaign 2026', 1, 24, false)
            ->willReturn(['images' => [], 'total' => 0]);
        $this->files->expects($this->never())->method('searchInTree');

        $this->library()->listImages('Campaign 2026', 1, 24, false, 'a');
    }

    public function testActiveQuerySearchesFolderTree(): void
    {
        $this->files->expects($this->once())
            ->method('searchInTree')
            ->with('Campaign 2026', 'Bitmotion', 'Campaign 2026', 1, 24, false)
            ->willReturn(['images' => [], 'total' => 0]);
        $this->files->expects($this->never())->method('listInFolder');

        $this->library()->listImages('Campaign 2026', 1, 24, false, 'Bitmotion');
    }

    public function testEverywhereSearchUsesRootTreeWithSelectedFolderBoost(): void
    {
        $this->files->expects($this->once())
            ->method('searchInTree')
            ->with('', 'Bitmotion', 'Campaign 2026', 1, 24, false)
            ->willReturn(['images' => [], 'total' => 0]);

        $this->library()->listImages('Campaign 2026', 1, 24, false, 'Bitmotion', true);
    }

    public function testWhitespaceOnlyQueryUsesFolderListing(): void
    {
        $this->files->expects($this->once())
            ->method('listInFolder')
            ->with('', 1, 24, false)
            ->willReturn(['images' => [], 'total' => 0]);
        $this->files->expects($this->never())->method('searchInTree');

        $this->library()->listImages('', 1, 24, false, "  \t ");
    }

    public function testSearchMapsRepositoryFilesToListedImages(): void
    {
        $match = $this->file('bitmotion.png', 'Brand/bitmotion.png', 10, 10, 50, 99);
        $this->files->expects($this->once())
            ->method('searchInTree')
            ->willReturn(['images' => [$match], 'total' => 1]);
        $this->renditions->method('mapReadyByFileIds')->willReturn([]);

        $page = $this->library()->listImages('Brand', 1, 24, false, 'bit');

        $this->assertSame(1, $page['total']);
        $this->assertCount(1, $page['images']);
        $this->assertSame('bitmotion.png', $page['images'][0]->name);
        $this->assertSame('Brand/bitmotion.png', $page['images'][0]->path);
    }

    public function testListedImageUsesThumbAndStandardPathsWhenReady(): void
    {
        $file = $this->file('hero.png', 'Brand/hero.png', 2000, 1000, 5000, 1);
        $file->setId(11);
        $thumb = (new MediaRendition())
            ->setPreset(RenditionPreset::Thumb)
            ->setStoragePath('_renditions/Brand/hero_400.png')
            ->setState(RenditionState::Ready);
        $standard = (new MediaRendition())
            ->setPreset(RenditionPreset::Standard)
            ->setStoragePath('_renditions/Brand/hero_1600.png')
            ->setState(RenditionState::Ready);

        $this->files->method('listInFolder')->willReturn(['images' => [$file], 'total' => 1]);
        $this->renditions->method('mapReadyByFileIds')->with([11])->willReturn([
            11 => [
                RenditionPreset::Thumb->value    => $thumb,
                RenditionPreset::Standard->value => $standard,
            ],
        ]);

        $page = $this->library()->listImages('', 1, 24);

        $this->assertSame('_renditions/Brand/hero_400.png', $page['images'][0]->thumbPath);
        $this->assertSame('_renditions/Brand/hero_1600.png', $page['images'][0]->insertPath);
    }

    public function testListedImageWithoutThumbUsesPlaceholderAndOriginalInsert(): void
    {
        $file = $this->file('hero.png', 'Brand/hero.png', 2000, 1000, 5000, 1);
        $file->setId(12);
        $this->files->method('listInFolder')->willReturn(['images' => [$file], 'total' => 1]);
        $this->renditions->expects($this->once())->method('mapReadyByFileIds')->with([12])->willReturn([]);

        $page = $this->library()->listImages('', 1, 24);

        $this->assertNull($page['images'][0]->thumbPath);
        $this->assertSame('Brand/hero.png', $page['images'][0]->insertPath);
    }

    public function testToListedImageWithEmptyReadyMapDoesNotQueryAgain(): void
    {
        $file = $this->file('hero.png', 'Brand/hero.png', 2000, 1000, 5000, 1);
        $file->setId(12);
        $this->renditions->expects($this->never())->method('mapReadyByFileIds');

        $listed = $this->library()->toListedImage($file, []);

        $this->assertNull($listed->thumbPath);
        $this->assertSame('Brand/hero.png', $listed->insertPath);
    }

    public function testSvgListedImageUsesOriginalForThumbAndInsert(): void
    {
        $file = $this->file('icon.svg', 'Brand/icon.svg', 100, 100, 20, 1);
        $file->setId(13);
        $this->files->method('listInFolder')->willReturn(['images' => [$file], 'total' => 1]);
        $this->renditions->method('mapReadyByFileIds')->willReturn([]);

        $page = $this->library()->listImages('', 1, 24, true);

        $this->assertSame('Brand/icon.svg', $page['images'][0]->thumbPath);
        $this->assertSame('Brand/icon.svg', $page['images'][0]->insertPath);
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
