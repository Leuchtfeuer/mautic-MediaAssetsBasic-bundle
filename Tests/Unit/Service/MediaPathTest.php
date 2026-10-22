<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;

final class MediaPathTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/lf-media-path-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        if ('' === $this->directory || !is_dir($this->directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testResolvesANestedFolderInsideTheMediaRoot(): void
    {
        mkdir($this->directory.'/Brand/Campaign', 0775, true);
        $paths = $this->paths();

        $resolved = $paths->resolve('Brand/Campaign');

        $this->assertSame('Brand/Campaign', $resolved['relative']);
        $this->assertSame(realpath($this->directory.'/Brand/Campaign'), $resolved['absolute']);
    }

    public function testResolvesAFileInsideTheMediaRoot(): void
    {
        file_put_contents($this->directory.'/hero.png', 'png');

        $resolved = $this->paths()->resolveFile('hero.png');

        $this->assertSame('hero.png', $resolved['relative']);
        $this->assertSame(realpath($this->directory.'/hero.png'), $resolved['absolute']);
    }

    public function testParentRelativeReturnsTheDirectoryPath(): void
    {
        $paths = $this->paths();

        $this->assertSame('', $paths->parentRelative('hero.png'));
        $this->assertSame('Brand', $paths->parentRelative('Brand/hero.png'));
    }

    public function testResolveFileRejectsAnEmptyPath(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_PATH);

        $this->paths()->resolveFile('');
    }

    public function testResolveFileRejectsAMissingFile(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_PATH);

        $this->paths()->resolveFile('missing.png');
    }

    public function testResolveFileRejectsADirectory(): void
    {
        mkdir($this->directory.'/Brand', 0775, true);

        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_PATH);

        $this->paths()->resolveFile('Brand');
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_PATH);

        $this->paths()->resolve('../outside');
    }

    public function testRejectsAHiddenDirectorySegment(): void
    {
        mkdir($this->directory.'/.hidden', 0775, true);

        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_PATH);

        $this->paths()->resolve('.hidden');
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
}
