<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\GeneratedRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionState;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaRenditionRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageRenditionWriter;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionLock;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionManager;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionPaths;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RenditionManagerTest extends TestCase
{
    private string $directory = '';

    /** @var ImageRenditionWriter&MockObject */
    private MockObject $writer;

    /** @var MediaRenditionRepository&MockObject */
    private MockObject $renditions;

    /** @var EntityManagerInterface&MockObject */
    private MockObject $entityManager;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/lf-rendition-manager-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);

        $this->writer        = $this->createMock(ImageRenditionWriter::class);
        $this->renditions    = $this->createMock(MediaRenditionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->persisted     = [];

        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
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

    public function testSkipsSvgWithoutCallingWriter(): void
    {
        $file = $this->file('icon.svg', ImageInspector::SVG_MIME);
        $this->writer->expects($this->never())->method('write');

        $result = $this->manager()->ensureForFile($file);

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->generated);
    }

    public function testSkipsInactiveFiles(): void
    {
        $file = $this->file('gone.png', 'image/png');
        $file->setMissingSince(new \DateTimeImmutable());
        $this->writer->expects($this->never())->method('write');

        $result = $this->manager()->ensureForFile($file);

        $this->assertSame(1, $result->skipped);
    }

    public function testGeneratesMissingPresetsAndPersistsReadyRows(): void
    {
        $relative = 'hero.png';
        file_put_contents($this->directory.'/'.$relative, $this->png());
        $file = $this->file($relative, 'image/png');

        $this->renditions->method('findOneForFilePreset')->willReturn(null);
        $this->writer->method('isAnimatedGif')->willReturn(false);
        $this->writer->method('write')->willReturnCallback(
            function (string $absolute, string $sourceRelative, RenditionPreset $preset): GeneratedRendition {
                $path = '_renditions/hero_'.$preset->longEdge().'.png';

                return new GeneratedRendition(
                    $this->directory.'/'.$path,
                    $path,
                    'png',
                    $preset->longEdge(),
                    (int) round($preset->longEdge() / 2),
                    1234,
                );
            },
        );

        $result = $this->manager()->ensureForFile($file);

        $this->assertSame(2, $result->generated);
        $this->assertSame(0, $result->failed);
        $ready = array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof MediaRendition && $entity->isReady(),
        ));
        $this->assertCount(2, $ready);
    }

    public function testDoesNotRegenerateExistingReadyRenditions(): void
    {
        $relative = 'hero.png';
        file_put_contents($this->directory.'/'.$relative, $this->png());
        $file = $this->file($relative, 'image/png');

        mkdir($this->directory.'/_renditions', 0775, true);
        $existingThumb = (new MediaRendition())
            ->setFile($file)
            ->setPreset(RenditionPreset::Thumb)
            ->setStoragePath('_renditions/hero_400.png')
            ->setState(RenditionState::Ready);
        $existingStandard = (new MediaRendition())
            ->setFile($file)
            ->setPreset(RenditionPreset::Standard)
            ->setStoragePath('_renditions/hero_1600.png')
            ->setState(RenditionState::Ready);
        file_put_contents($this->directory.'/_renditions/hero_400.png', 'x');
        file_put_contents($this->directory.'/_renditions/hero_1600.png', 'x');

        $this->renditions->method('findOneForFilePreset')->willReturnCallback(
            static fn (MediaAssetFile $f, RenditionPreset $preset): MediaRendition => RenditionPreset::Thumb === $preset
                ? $existingThumb
                : $existingStandard,
        );
        $this->writer->method('isAnimatedGif')->willReturn(false);
        $this->writer->expects($this->never())->method('write');

        $result = $this->manager()->ensureForFile($file);

        $this->assertSame(2, $result->skipped);
        $this->assertSame(0, $result->generated);
    }

    public function testRebuildRemovesReadyRowsAndRegenerates(): void
    {
        $relative = 'hero.png';
        file_put_contents($this->directory.'/'.$relative, $this->png());
        $file = $this->file($relative, 'image/png');

        mkdir($this->directory.'/_renditions', 0775, true);
        $stale = (new MediaRendition())
            ->setFile($file)
            ->setPreset(RenditionPreset::Thumb)
            ->setStoragePath('_renditions/hero_400.png')
            ->setState(RenditionState::Ready);
        file_put_contents($this->directory.'/_renditions/hero_400.png', 'stale');

        $this->renditions->method('findForFile')->willReturn([$stale]);
        $this->renditions->method('findOneForFilePreset')->willReturn(null);
        $this->writer->method('isAnimatedGif')->willReturn(false);
        $this->writer->expects($this->exactly(2))->method('write')->willReturnCallback(
            function (string $absolute, string $sourceRelative, RenditionPreset $preset): GeneratedRendition {
                $path = '_renditions/hero_'.$preset->longEdge().'.png';

                return new GeneratedRendition(
                    $this->directory.'/'.$path,
                    $path,
                    'png',
                    $preset->longEdge(),
                    100,
                    50,
                );
            },
        );
        $this->entityManager->expects($this->atLeastOnce())->method('remove')->with($stale);

        $result = $this->manager()->rebuildForFile($file);

        $this->assertSame(2, $result->generated);
        $this->assertFileDoesNotExist($this->directory.'/_renditions/hero_400.png');
    }

    public function testRetriesFailedRowsOnNextEnsure(): void
    {
        $relative = 'hero.png';
        file_put_contents($this->directory.'/'.$relative, $this->png());
        $file = $this->file($relative, 'image/png');

        $failed = (new MediaRendition())
            ->setFile($file)
            ->setPreset(RenditionPreset::Thumb)
            ->setStoragePath('_renditions/hero_400.png')
            ->setState(RenditionState::Failed)
            ->setFailureReason('corrupt');

        $this->renditions->method('findOneForFilePreset')->willReturnCallback(
            static fn (MediaAssetFile $f, RenditionPreset $preset): ?MediaRendition => RenditionPreset::Thumb === $preset ? $failed : null,
        );
        $this->writer->method('isAnimatedGif')->willReturn(false);
        $this->writer->method('write')->willReturnCallback(
            function (string $absolute, string $sourceRelative, RenditionPreset $preset): ?GeneratedRendition {
                if (RenditionPreset::Standard === $preset) {
                    return null;
                }

                return new GeneratedRendition(
                    $this->directory.'/_renditions/hero_400.png',
                    '_renditions/hero_400.png',
                    'png',
                    400,
                    200,
                    99,
                );
            },
        );

        $result = $this->manager()->ensureForFile($file);

        $this->assertSame(1, $result->generated);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(RenditionState::Ready, $failed->getState());
        $this->assertNull($failed->getFailureReason());
    }

    public function testMarksFailedWhenWriterThrows(): void
    {
        $relative = 'bad.png';
        file_put_contents($this->directory.'/'.$relative, $this->png());
        $file = $this->file($relative, 'image/png');

        $this->renditions->method('findOneForFilePreset')->willReturn(null);
        $this->writer->method('isAnimatedGif')->willReturn(false);
        $this->writer->method('write')->willThrowException(new \RuntimeException('decode failed'));

        $result = $this->manager()->ensureForFile($file);

        $this->assertSame(2, $result->failed);
        $failed = array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof MediaRendition && RenditionState::Failed === $entity->getState(),
        ));
        $this->assertCount(2, $failed);
        $this->assertSame('decode failed', $failed[0]->getFailureReason());
    }

    private function manager(): RenditionManager
    {
        $helper = $this->createMock(PathsHelper::class);
        $helper->method('getImagePath')->willReturn($this->directory.'/');
        $helper->method('getSystemPath')->willReturnCallback(
            fn (string $name, bool $full = false): string => 'tmp' === $name ? $this->directory : 'media/images',
        );
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('');
        $paths = new MediaPath(new ImageUrls($helper, $parameters));

        return new RenditionManager(
            $this->writer,
            new RenditionPaths($paths),
            $paths,
            $this->renditions,
            $this->entityManager,
            new RenditionLock($helper, 5, 1),
            new ImageInspector(),
        );
    }

    private function file(string $relative, string $mime): MediaAssetFile
    {
        $file = new MediaAssetFile();
        $file->setRelativePath($relative)
            ->setFilename(basename($relative))
            ->setMime($mime)
            ->setWidth(10)
            ->setHeight(10)
            ->setBytes(10)
            ->setFileMtime(time());

        return $file;
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }
}
