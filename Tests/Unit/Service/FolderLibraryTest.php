<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FolderLibrary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;

final class FolderLibraryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/lf-media-folders-'.bin2hex(random_bytes(4));
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

    public function testBuildsATreeWithRecursiveImageCounts(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        mkdir($this->directory.'/Brand/Campaign', 0775, true);
        $storage->store($this->directory, 'root.png', $this->png());
        $storage->store($this->directory.'/Brand', 'brand.png', $this->png(), 'Brand');
        $storage->store($this->directory.'/Brand/Campaign', 'campaign.png', $this->png(), 'Brand/Campaign');
        file_put_contents($this->directory.'/notes.txt', 'skip');

        $tree = $this->library()->tree('Home');

        $this->assertSame('Home', $tree->name);
        $this->assertSame('', $tree->path);
        $this->assertSame(3, $tree->count);
        $this->assertCount(1, $tree->children);
        $this->assertSame('Brand', $tree->children[0]->name);
        $this->assertSame(2, $tree->children[0]->count);
        $this->assertSame('Campaign', $tree->children[0]->children[0]->name);
        $this->assertSame(1, $tree->children[0]->children[0]->count);
    }

    public function testCreatesAFolderUnderTheCurrentPath(): void
    {
        $created = $this->library()->create('', 'Campaign 2026');

        $this->assertSame('Campaign-2026', $created->name);
        $this->assertSame('Campaign-2026', $created->path);
        $this->assertDirectoryExists($this->directory.'/Campaign-2026');
    }

    public function testSkipsHiddenDirectoriesAndTheirImages(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        mkdir($this->directory.'/.hidden', 0775, true);
        mkdir($this->directory.'/Brand', 0775, true);
        $storage->store($this->directory.'/.hidden', 'secret.png', $this->png(), '.hidden');
        $storage->store($this->directory.'/Brand', 'brand.png', $this->png(), 'Brand');

        $tree = $this->library()->tree('Home');

        $this->assertSame(1, $tree->count);
        $this->assertCount(1, $tree->children);
        $this->assertSame('Brand', $tree->children[0]->name);
        $this->assertSame(['Brand'], array_map(static fn (\MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\FolderNode $child) => $child->path, $tree->children));
    }

    public function testRejectsCreatingAHiddenFolderName(): void
    {
        $this->expectException(ImageRejectedException::class);
        $this->expectExceptionMessage(Translation::ERROR_FOLDER_NAME);

        $this->library()->create('', '.hidden');
    }

    private function library(): FolderLibrary
    {
        $helper = $this->createMock(PathsHelper::class);
        $helper->method('getImagePath')->willReturn($this->directory.'/');
        $helper->method('getSystemPath')->willReturn('media/images');

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('');

        return new FolderLibrary(new MediaPath(new ImageUrls($helper, $parameters)), new ImageInspector());
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }
}
