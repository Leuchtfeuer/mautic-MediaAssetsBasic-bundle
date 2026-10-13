<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFolderRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FolderLibrary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaIndex;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FolderLibraryTest extends TestCase
{
    private string $directory = '';

    /** @var MediaAssetFolderRepository&MockObject */
    private MockObject $folders;

    /** @var MediaAssetFileRepository&MockObject */
    private MockObject $files;

    /** @var EntityManagerInterface&MockObject */
    private MockObject $entityManager;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/lf-media-folders-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $this->directory = $directory;

        $this->folders       = $this->createMock(MediaAssetFolderRepository::class);
        $this->files         = $this->createMock(MediaAssetFileRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
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

    public function testBuildsATreeWithRecursiveImageCountsFromTheIndex(): void
    {
        $brand    = $this->folder('Brand', 'Brand', null);
        $campaign = $this->folder('Campaign', 'Brand/Campaign', $brand);

        $this->folders->method('findActiveFolders')->willReturn([$brand, $campaign]);
        $this->files->method('findActiveFiles')->willReturn([
            $this->indexedFile('root.png'),
            $this->indexedFile('Brand/brand.png'),
            $this->indexedFile('Brand/Campaign/campaign.png'),
        ]);

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

    public function testNestsFoldersByRelativePathEvenWhenParentAssociationIsMissing(): void
    {
        $test = $this->folder('test', 'test', null);
        $ttt  = $this->folder('ttt', 'test/ttt', null);

        $this->folders->method('findActiveFolders')->willReturn([$test, $ttt]);
        $this->files->method('findActiveFiles')->willReturn([
            $this->indexedFile('test/a.png'),
            $this->indexedFile('test/ttt/b.png'),
        ]);

        $tree = $this->library()->tree('Home');

        $this->assertCount(1, $tree->children);
        $this->assertSame('test', $tree->children[0]->path);
        $this->assertSame(2, $tree->children[0]->count);
        $this->assertCount(1, $tree->children[0]->children);
        $this->assertSame('test/ttt', $tree->children[0]->children[0]->path);
        $this->assertSame(1, $tree->children[0]->children[0]->count);
    }

    public function testCreatesAFolderUnderTheCurrentPath(): void
    {
        $this->folders->method('findOneByRelativePath')->willReturn(null);
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $created = $this->library()->create('', 'Campaign 2026');

        $this->assertSame('Campaign-2026', $created->name);
        $this->assertSame('Campaign-2026', $created->path);
        $this->assertDirectoryExists($this->directory.'/Campaign-2026');
    }

    public function testOmitsHiddenFoldersFromTheTree(): void
    {
        $visible = $this->folder('Brand', 'Brand', null);

        $this->folders->method('findActiveFolders')->willReturn([$visible]);
        $this->files->method('findActiveFiles')->willReturn([
            $this->indexedFile('Brand/brand.png'),
        ]);

        $tree = $this->library()->tree('Home');

        $this->assertSame(1, $tree->count);
        $this->assertCount(1, $tree->children);
        $this->assertSame('Brand', $tree->children[0]->name);
    }

    public function testExcludesSvgFromFolderCountsUnlessAllowed(): void
    {
        $brand = $this->folder('Brand', 'Brand', null);

        $this->folders->method('findActiveFolders')->willReturn([$brand]);
        $this->files->method('findActiveFiles')->willReturn([
            $this->indexedFile('Brand/brand.png'),
            $this->indexedFile('Brand/icon.svg', ImageInspector::SVG_MIME),
        ]);

        $withoutSvg = $this->library()->tree('Home', false);
        $this->assertSame(1, $withoutSvg->count);
        $this->assertSame(1, $withoutSvg->children[0]->count);

        $withSvg = $this->library()->tree('Home', true);
        $this->assertSame(2, $withSvg->count);
        $this->assertSame(2, $withSvg->children[0]->count);
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

        $paths = new MediaPath(new ImageUrls($helper, $parameters));

        return new FolderLibrary(
            $paths,
            $this->folders,
            $this->files,
            new MediaIndex($paths, new ImageInspector(), $this->folders, $this->files, $this->entityManager),
        );
    }

    private function folder(string $name, string $relativePath, ?MediaAssetFolder $parent): MediaAssetFolder
    {
        $folder = new MediaAssetFolder();
        $folder->setName($name)
            ->setRelativePath($relativePath)
            ->setParent($parent);

        return $folder;
    }

    private function indexedFile(string $relativePath, string $mime = 'image/png'): MediaAssetFile
    {
        $file = new MediaAssetFile();
        $file->setRelativePath($relativePath)
            ->setFilename(basename($relativePath))
            ->setMime($mime)
            ->setWidth(1)
            ->setHeight(1)
            ->setBytes(10)
            ->setFileMtime(1);

        return $file;
    }
}
