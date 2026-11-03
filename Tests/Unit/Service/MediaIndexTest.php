<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\RenditionEnsureResult;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\RenditionBusyException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFolderRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaIndex;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MediaIndexTest extends TestCase
{
    private string $directory = '';

    /** @var EntityManagerInterface&MockObject */
    private MockObject $entityManager;

    /** @var MediaAssetFolderRepository&MockObject */
    private MockObject $folders;

    /** @var MediaAssetFileRepository&MockObject */
    private MockObject $files;

    /** @var array<string, MediaAssetFolder> */
    private array $folderStore = [];

    /** @var array<string, MediaAssetFile> */
    private array $fileStore = [];

    /** @var list<object> */
    private array $persisted = [];

    /** @var (RenditionManager&MockObject)|null */
    private ?MockObject $renditions = null;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/lf-media-index-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $this->directory = $directory;

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->folders       = $this->createMock(MediaAssetFolderRepository::class);
        $this->files         = $this->createMock(MediaAssetFileRepository::class);
        $this->folderStore   = $this->emptyFolderStore();
        $this->fileStore     = $this->emptyFileStore();
        $this->persisted     = [];

        $this->folders->method('findOneByRelativePath')->willReturnCallback(
            fn (string $path): ?MediaAssetFolder => $this->folderStore[$path] ?? null,
        );
        $this->folders->method('findActiveFolders')->willReturnCallback(
            function (): array {
                $active = [];
                foreach ($this->folderStore as $folder) {
                    if ($folder->isActive()) {
                        $active[] = $folder;
                    }
                }

                return $active;
            },
        );
        $this->files->method('findOneByRelativePath')->willReturnCallback(
            fn (string $path): ?MediaAssetFile => $this->fileStore[$path] ?? null,
        );
        $this->files->method('findAllIndexed')->willReturnCallback(
            fn (): array => array_values($this->fileStore),
        );
        $this->files->method('hasOtherActiveWithChecksum')->willReturnCallback(
            function (string $checksum, ?string $excludeRelativePath = null): bool {
                foreach ($this->fileStore as $file) {
                    if (!$file->isActive()) {
                        continue;
                    }
                    if ($file->getChecksum() !== $checksum) {
                        continue;
                    }
                    if (null !== $excludeRelativePath && $file->getRelativePath() === $excludeRelativePath) {
                        continue;
                    }

                    return true;
                }

                return false;
            },
        );
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof MediaAssetFolder) {
                $this->folderStore[$entity->getRelativePath()] = $entity;
            }
            if ($entity instanceof MediaAssetFile) {
                $this->fileStore[$entity->getRelativePath()] = $entity;
            }
        });
    }

    /**
     * @return array<string, MediaAssetFolder>
     */
    private function emptyFolderStore(): array
    {
        return [];
    }

    /**
     * @return array<string, MediaAssetFile>
     */
    private function emptyFileStore(): array
    {
        return [];
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

    public function testDryRunDoesNotFlushOrPersist(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $storage->store($this->directory, 'hero.png', $this->png());

        $this->entityManager->expects($this->never())->method('flush');
        $this->entityManager->expects($this->never())->method('persist');

        $summary = $this->index()->run(false, true);

        $this->assertSame(1, $summary->filesIndexed);
    }

    public function testIndexesNestedFoldersOnceAndSetsParentRelation(): void
    {
        mkdir($this->directory.'/test/ttt', 0775, true);
        (new ImageStorage(new ImageInspector()))->store($this->directory.'/test/ttt', 'nested.png', $this->png(), 'test/ttt');

        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $summary = $this->index()->run(false, false);

        $this->assertSame(1, $summary->filesIndexed);
        $this->assertSame(2, $summary->foldersIndexed);
        $this->assertArrayHasKey('test', $this->folderStore);
        $this->assertArrayHasKey('test/ttt', $this->folderStore);
        $this->assertSame($this->folderStore['test'], $this->folderStore['test/ttt']->getParent());
        $this->assertCount(1, array_filter($this->persisted, static fn (object $e): bool => $e instanceof MediaAssetFolder && 'test' === $e->getRelativePath()));
    }

    public function testIndexesEmptyFolders(): void
    {
        mkdir($this->directory.'/EmptyBrand', 0775, true);

        $summary = $this->index()->run(false, false);

        $this->assertSame(1, $summary->foldersIndexed);
        $this->assertSame(0, $summary->filesIndexed);
        $this->assertArrayHasKey('EmptyBrand', $this->folderStore);
    }

    public function testSkipsNonImagesDotfilesAndDoesNotAbort(): void
    {
        (new ImageStorage(new ImageInspector()))->store($this->directory, 'ok.png', $this->png());
        file_put_contents($this->directory.'/notes.txt', 'skip');
        mkdir($this->directory.'/.hidden', 0775, true);
        file_put_contents($this->directory.'/.hidden/secret.png', $this->png());

        $summary = $this->index()->run(false, false);

        $this->assertSame(1, $summary->filesIndexed);
        $this->assertGreaterThanOrEqual(1, $summary->skipped);
        $this->assertArrayNotHasKey('.hidden', $this->folderStore);
    }

    public function testMarksIndexedFilesMissingWhenTheyDisappearFromDisk(): void
    {
        $missing = new MediaAssetFile();
        $missing->setRelativePath('gone.png')
            ->setFilename('gone.png')
            ->setMime('image/png')
            ->setWidth(1)
            ->setHeight(1)
            ->setBytes(10)
            ->setFileMtime(1);
        $this->fileStore['gone.png'] = $missing;

        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $summary = $this->index()->run(false, false);

        $this->assertSame(1, $summary->filesSoftDeleted);
        $this->assertInstanceOf(\DateTimeImmutable::class, $missing->getMissingSince());
    }

    public function testRepairsMissingParentOnExistingFolder(): void
    {
        mkdir($this->directory.'/test/ttt', 0775, true);

        $parent = new MediaAssetFolder();
        $parent->setRelativePath('test')->setName('test');
        $child = new MediaAssetFolder();
        $child->setRelativePath('test/ttt')->setName('ttt')->setParent(null);
        $this->folderStore['test']     = $parent;
        $this->folderStore['test/ttt'] = $child;

        $this->index()->run(false, false);

        $this->assertSame($parent, $child->getParent());
    }

    public function testDefaultRunSkipsUnchangedFilesButAllForcesUpdate(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $image   = $storage->store($this->directory, 'hero.png', $this->png());
        $mtime   = filemtime($this->directory.'/'.$image->name);
        $this->assertNotFalse($mtime);
        $checksum = hash_file('sha256', $this->directory.'/'.$image->name);
        $this->assertIsString($checksum);

        $existing = new MediaAssetFile();
        $existing->setRelativePath($image->name)
            ->setFilename($image->name)
            ->setMime('image/png')
            ->setWidth($image->width)
            ->setHeight($image->height)
            ->setBytes($image->bytes)
            ->setFileMtime($mtime)
            ->setChecksum($checksum)
            ->setDateAdded(new \DateTimeImmutable());
        $this->fileStore[$image->name] = $existing;

        $default = $this->index()->run(false, false);
        $this->assertSame(0, $default->filesIndexed);
        $this->assertSame(0, $default->filesUpdated);

        $all = $this->index()->run(true, false);
        $this->assertSame(0, $all->filesIndexed);
        $this->assertSame(1, $all->filesUpdated);
    }

    public function testNullChecksumIsBackfilledEvenWhenMtimeAndBytesMatch(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $image   = $storage->store($this->directory, 'hero.png', $this->png());
        $mtime   = filemtime($this->directory.'/'.$image->name);
        $this->assertNotFalse($mtime);

        $existing = new MediaAssetFile();
        $existing->setRelativePath($image->name)
            ->setFilename($image->name)
            ->setMime('image/png')
            ->setWidth($image->width)
            ->setHeight($image->height)
            ->setBytes($image->bytes)
            ->setFileMtime($mtime);
        $this->fileStore[$image->name] = $existing;

        $summary = $this->index()->run(false, false);

        $this->assertSame(0, $summary->filesIndexed);
        $this->assertSame(1, $summary->filesUpdated);
        $this->assertNotNull($existing->getChecksum());
        $this->assertInstanceOf(\DateTimeImmutable::class, $existing->getDateAdded());
    }

    public function testReportsDuplicateChecksumAmongNewlyIndexedFiles(): void
    {
        $storage = new ImageStorage(new ImageInspector());
        $storage->store($this->directory, 'a.png', $this->png());
        mkdir($this->directory.'/other', 0775, true);
        $storage->store($this->directory.'/other', 'b.png', $this->png(), 'other');

        $summary = $this->index()->run(false, false);

        $this->assertSame(2, $summary->filesIndexed);
        $this->assertSame(1, $summary->filesIndexedDuplicateChecksum);
    }

    public function testSkipRenditionsDoesNotCallRenditionManager(): void
    {
        (new ImageStorage(new ImageInspector()))->store($this->directory, 'hero.png', $this->png());

        $renditions = $this->createMock(RenditionManager::class);
        $renditions->expects($this->never())->method('ensureForFile');
        $renditions->expects($this->never())->method('rebuildForFile');
        $this->renditions = $renditions;

        $summary = $this->index()->run(false, false, true);

        $this->assertSame(1, $summary->filesIndexed);
        $this->assertSame(0, $summary->renditionsGenerated);
    }

    public function testDefaultRunRequestsRenditionGeneration(): void
    {
        (new ImageStorage(new ImageInspector()))->store($this->directory, 'hero.png', $this->png());

        $renditions = $this->createMock(RenditionManager::class);
        $renditions->expects($this->atLeastOnce())
            ->method('rebuildForFile')
            ->willReturn(new RenditionEnsureResult(generated: 1, skipped: 1));
        $renditions->expects($this->never())->method('ensureForFile');
        $this->renditions = $renditions;

        $summary = $this->index()->run(false, false, false);

        $this->assertSame(1, $summary->filesIndexed);
        $this->assertSame(1, $summary->renditionsGenerated);
        $this->assertSame(1, $summary->renditionsSkipped);
    }

    public function testRegisterUploadedRebuildsRenditions(): void
    {
        $listed = (new ImageStorage(new ImageInspector()))->store($this->directory, 'hero.png', $this->png());

        $renditions = $this->createMock(RenditionManager::class);
        $renditions->expects($this->once())
            ->method('rebuildForFile')
            ->willReturn(new RenditionEnsureResult(generated: 2));
        $renditions->expects($this->never())->method('ensureForFile');
        $this->renditions = $renditions;

        $this->index()->registerUploaded($listed, false);
    }

    public function testRegisterUploadedKeepsIndexWhenRenditionLockIsBusy(): void
    {
        $listed = (new ImageStorage(new ImageInspector()))->store($this->directory, 'hero.png', $this->png());

        $renditions = $this->createMock(RenditionManager::class);
        $renditions->expects($this->once())
            ->method('rebuildForFile')
            ->willThrowException(new RenditionBusyException(5));
        $this->renditions = $renditions;

        $this->index()->registerUploaded($listed, false);

        $this->assertArrayHasKey('hero.png', $this->fileStore);
    }

    public function testRegisterMovedKeepsIndexWhenRenditionLockIsBusy(): void
    {
        $listed = (new ImageStorage(new ImageInspector()))->store($this->directory, 'hero.png', $this->png());
        $this->index()->registerUploaded($listed, false, false);

        mkdir($this->directory.'/album', 0775, true);
        rename($this->directory.'/hero.png', $this->directory.'/album/hero.png');
        $moved = new ListedImage(
            'hero.png',
            $listed->width,
            $listed->height,
            $listed->bytes,
            'album/hero.png',
        );

        $renditions = $this->createMock(RenditionManager::class);
        $renditions->expects($this->once())
            ->method('rebuildForFile')
            ->willThrowException(new RenditionBusyException(5));
        $this->renditions = $renditions;

        $this->index()->registerMoved('hero.png', $moved, false);

        $this->assertArrayHasKey('album/hero.png', $this->fileStore);
        $this->assertSame('album/hero.png', $this->fileStore['album/hero.png']->getRelativePath());
    }

    public function testSkipsFilesUnderTheRenditionsRoot(): void
    {
        mkdir($this->directory.'/_renditions/brand', 0775, true);
        file_put_contents($this->directory.'/_renditions/brand/hero_400.png', $this->png());
        (new ImageStorage(new ImageInspector()))->store($this->directory, 'visible.png', $this->png());

        $summary = $this->index()->run(false, false, true);

        $this->assertSame(1, $summary->filesIndexed);
        $this->assertArrayHasKey('visible.png', $this->fileStore);
        $this->assertArrayNotHasKey('_renditions/brand/hero_400.png', $this->fileStore);
    }

    private function index(): MediaIndex
    {
        $helper = $this->createMock(PathsHelper::class);
        $helper->method('getImagePath')->willReturn($this->directory.'/');
        $helper->method('getSystemPath')->willReturn('media/images');

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('');

        if (null === $this->renditions) {
            $this->renditions = $this->createMock(RenditionManager::class);
            $this->renditions->method('ensureForFile')->willReturn(new RenditionEnsureResult());
            $this->renditions->method('rebuildForFile')->willReturn(new RenditionEnsureResult());
        }

        return new MediaIndex(
            new MediaPath(new ImageUrls($helper, $parameters)),
            new ImageInspector(),
            $this->folders,
            $this->files,
            $this->entityManager,
            $this->renditions,
        );
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }
}
