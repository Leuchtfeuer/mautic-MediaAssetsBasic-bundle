<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Functional\Command;

use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionState;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionPaths;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class IndexMediaCommandFunctionalTest extends MauticMysqlTestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    /** @var list<string> */
    private array $createdFolders = [];

    private int $errorHandlerCountBeforeSetup = 0;

    private int $exceptionHandlerCountBeforeSetup = 0;

    protected function setUp(): void
    {
        $this->errorHandlerCountBeforeSetup     = $this->countErrorHandlers();
        $this->exceptionHandlerCountBeforeSetup = $this->countExceptionHandlers();

        parent::setUp();
    }

    protected function beforeTearDown(): void
    {
        $directory = $this->mediaRoot();
        foreach ($this->createdFiles as $relative) {
            $path = $directory.'/'.$relative;
            if (is_file($path)) {
                unlink($path);
            }
            foreach (RenditionPreset::cases() as $preset) {
                $derived = $directory.'/'.RenditionPaths::relativePathFor($relative, $preset);
                if (is_file($derived)) {
                    unlink($derived);
                }
            }
        }
        $this->removeEmptyDirsUnder($directory.'/'.RenditionPaths::ROOT);
        foreach (array_reverse($this->createdFolders) as $relative) {
            $path = $directory.'/'.$relative;
            if (is_dir($path)) {
                @rmdir($path);
            }
        }

        while ($this->countErrorHandlers() > $this->errorHandlerCountBeforeSetup) {
            restore_error_handler();
        }

        $exceptionHandlerTarget = $this->exceptionHandlerCountBeforeSetup + 1;
        while ($this->countExceptionHandlers() > $exceptionHandlerTarget) {
            restore_exception_handler();
        }
    }

    public function testIndexesNestedFoldersAndFilesAndIsIdempotent(): void
    {
        $folder = 'Brand'.bin2hex(random_bytes(3));
        $nested = $folder.'/Campaign';
        $this->mkdirRelative($folder);
        $this->mkdirRelative($nested);
        $rootName   = $this->writePng('root-'.bin2hex(random_bytes(2)).'.png');
        $nestedName = $this->writePng('nested-'.bin2hex(random_bytes(2)).'.png', $nested);

        $display = $this->runIndexCommand();
        $this->assertStringContainsString('Media index run finished.', $display);

        $rootFile = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $rootName]);
        $this->assertInstanceOf(MediaAssetFile::class, $rootFile);
        $this->assertNotInstanceOf(\DateTimeImmutable::class, $rootFile->getMissingSince());
        $this->assertSame(1, $rootFile->getWidth());

        $nestedFile = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $nested.'/'.$nestedName]);
        $this->assertInstanceOf(MediaAssetFile::class, $nestedFile);

        $parentFolder = $this->em->getRepository(MediaAssetFolder::class)->findOneBy(['relativePath' => $folder]);
        $childFolder  = $this->em->getRepository(MediaAssetFolder::class)->findOneBy(['relativePath' => $nested]);
        $this->assertInstanceOf(MediaAssetFolder::class, $parentFolder);
        $this->assertInstanceOf(MediaAssetFolder::class, $childFolder);
        $this->assertSame($parentFolder->getId(), $childFolder->getParent()?->getId());

        $filesBefore   = (int) $this->em->getRepository(MediaAssetFile::class)->count([]);
        $foldersBefore = (int) $this->em->getRepository(MediaAssetFolder::class)->count([]);

        $second = $this->runIndexCommand();
        $this->assertStringContainsString('Media index run finished.', $second);

        $this->assertSame($filesBefore, (int) $this->em->getRepository(MediaAssetFile::class)->count([]));
        $this->assertSame($foldersBefore, (int) $this->em->getRepository(MediaAssetFolder::class)->count([]));
        $this->assertNotInstanceOf(
            \DateTimeImmutable::class, $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $rootName])?->getMissingSince()
        );
    }

    public function testMarksMissingFilesAndClearsTheMarkWhenRestored(): void
    {
        $name = $this->writePng('gone-'.bin2hex(random_bytes(2)).'.png');
        $this->runIndexCommand();

        $file = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);
        $this->assertNotInstanceOf(\DateTimeImmutable::class, $file->getMissingSince());

        unlink($this->mediaRoot().'/'.$name);
        $this->createdFiles = array_values(array_filter(
            $this->createdFiles,
            static fn (string $relative): bool => $relative !== $name,
        ));

        $this->runIndexCommand();
        $this->em->clear();

        $missing = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $missing);
        $this->assertInstanceOf(\DateTimeImmutable::class, $missing->getMissingSince());

        $this->writePng($name);
        $this->runIndexCommand(['--all' => true]);
        $this->em->clear();

        $restored = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $restored);
        $this->assertNotInstanceOf(\DateTimeImmutable::class, $restored->getMissingSince());
    }

    public function testDryRunDoesNotWriteRows(): void
    {
        $name   = 'dry-'.bin2hex(random_bytes(2)).'.png';
        $before = (int) $this->em->getRepository(MediaAssetFile::class)->count(['relativePath' => $name]);
        $this->writePng($name);

        $display = $this->runIndexCommand(['--dry-run' => true]);
        $this->assertStringContainsString('Dry run', $display);

        $after = (int) $this->em->getRepository(MediaAssetFile::class)->count(['relativePath' => $name]);
        $this->assertSame($before, $after);
    }

    public function testIndexesLargeImageWithStandardAndThumbRenditions(): void
    {
        $folder = 'Rendition'.bin2hex(random_bytes(3));
        $this->mkdirRelative($folder);
        $name = $this->writeJpeg('large-'.bin2hex(random_bytes(2)).'.jpg', $folder, 2000, 1200);

        $display = $this->runIndexCommand();
        $this->assertStringContainsString('Renditions generated', $display);

        $relative = $folder.'/'.$name;
        $file     = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $relative]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);

        $rows = $this->em->getRepository(MediaRendition::class)->findBy(['file' => $file]);
        $this->assertCount(2, $rows);

        $byPreset = [];
        foreach ($rows as $row) {
            $byPreset[$row->getPreset()->value] = $row;
            $this->assertSame(RenditionState::Ready, $row->getState());
            $this->assertTrue(is_file($this->mediaRoot().'/'.$row->getStoragePath()));
            $this->assertGreaterThan(0, $row->getBytes());
            $this->assertGreaterThan(0, $row->getWidth());
        }

        $this->assertArrayHasKey(RenditionPreset::Standard->value, $byPreset);
        $this->assertArrayHasKey(RenditionPreset::Thumb->value, $byPreset);
        $this->assertSame(1600, max($byPreset[RenditionPreset::Standard->value]->getWidth(), $byPreset[RenditionPreset::Standard->value]->getHeight()));
        $this->assertSame(400, max($byPreset[RenditionPreset::Thumb->value]->getWidth(), $byPreset[RenditionPreset::Thumb->value]->getHeight()));
        $this->assertNotInstanceOf(MediaAssetFile::class, $this->em->getRepository(MediaAssetFile::class)->findOneBy([
            'relativePath' => $byPreset[RenditionPreset::Thumb->value]->getStoragePath(),
        ]));
    }

    public function testIndexesSmallImageWithThumbOnly(): void
    {
        $name = $this->writeJpeg('small-'.bin2hex(random_bytes(2)).'.jpg', '', 800, 600);
        $this->runIndexCommand();

        $file = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);

        $rows = $this->em->getRepository(MediaRendition::class)->findBy(['file' => $file]);
        $this->assertCount(2, $rows);
        $byPreset = [];
        foreach ($rows as $row) {
            $byPreset[$row->getPreset()->value] = $row;
        }
        $thumb    = $byPreset[RenditionPreset::Thumb->value] ?? null;
        $standard = $byPreset[RenditionPreset::Standard->value] ?? null;
        $this->assertInstanceOf(MediaRendition::class, $thumb);
        $this->assertInstanceOf(MediaRendition::class, $standard);
        $this->assertSame(RenditionState::Ready, $thumb->getState());
        $this->assertSame(RenditionState::NotNeeded, $standard->getState());
        $this->assertFalse(is_file($this->mediaRoot().'/'.RenditionPaths::relativePathFor($name, RenditionPreset::Standard)));
    }

    public function testSkipRenditionsIndexesWithoutWritingDerivedFiles(): void
    {
        $name = $this->writeJpeg('skip-'.bin2hex(random_bytes(2)).'.jpg', '', 1800, 900);

        $display = $this->runIndexCommand(['--skip-renditions' => true]);
        $this->assertStringContainsString('Skipping rendition generation.', $display);

        $file = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);
        $this->assertSame(0, (int) $this->em->getRepository(MediaRendition::class)->count(['file' => $file]));
        $this->assertFalse(is_file($this->mediaRoot().'/'.RenditionPaths::relativePathFor($name, RenditionPreset::Thumb)));

        $second = $this->runIndexCommand();
        $this->em->clear();

        $file = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);
        $rows = $this->em->getRepository(MediaRendition::class)->findBy(['file' => $file]);
        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('Renditions generated', $second);
    }

    public function testSecondRunDoesNotRegenerateExistingRenditions(): void
    {
        $name = $this->writeJpeg('idempotent-'.bin2hex(random_bytes(2)).'.jpg', '', 1800, 900);
        $this->runIndexCommand();

        $file = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);
        $before = $this->em->getRepository(MediaRendition::class)->findBy(['file' => $file]);
        $this->assertNotEmpty($before);
        $mtimes = [];
        foreach ($before as $row) {
            $path = $this->mediaRoot().'/'.$row->getStoragePath();
            $this->assertTrue(is_file($path));
            $mtimes[$row->getStoragePath()] = filemtime($path);
        }

        sleep(1);
        $display = $this->runIndexCommand();
        $this->assertStringContainsString('Renditions skipped', $display);

        foreach ($mtimes as $storagePath => $mtime) {
            $this->assertSame($mtime, filemtime($this->mediaRoot().'/'.$storagePath));
        }
    }

    public function testUnreadableImageDoesNotAbortTheRun(): void
    {
        $good = $this->writePng('good-'.bin2hex(random_bytes(2)).'.png');
        $bad  = 'bad-'.bin2hex(random_bytes(2)).'.jpg';
        file_put_contents($this->mediaRoot().'/'.$bad, 'not-a-jpeg');
        $this->createdFiles[] = $bad;

        $display = $this->runIndexCommand();
        $this->assertStringContainsString('Media index run finished.', $display);

        $goodFile = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $good]);
        $this->assertInstanceOf(MediaAssetFile::class, $goodFile);
        $this->assertNotInstanceOf(
            MediaAssetFile::class,
            $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $bad]),
        );
    }

    public function testFailedRenditionIsRetriedOnNextRun(): void
    {
        $name = $this->writeJpeg('retry-'.bin2hex(random_bytes(2)).'.jpg', '', 1800, 900);
        $this->runIndexCommand(['--skip-renditions' => true]);

        $file = $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]);
        $this->assertInstanceOf(MediaAssetFile::class, $file);

        $failed = (new MediaRendition())
            ->setFile($file)
            ->setPreset(RenditionPreset::Thumb)
            ->setFormat('')
            ->setWidth(0)
            ->setHeight(0)
            ->setBytes(0)
            ->setStoragePath(RenditionPaths::relativePathFor($name, RenditionPreset::Thumb))
            ->setState(RenditionState::Failed)
            ->setFailureReason('previous failure')
            ->setDateAdded(new \DateTimeImmutable())
            ->setDateModified(new \DateTimeImmutable());
        $this->em->persist($failed);
        $this->em->flush();

        $this->runIndexCommand();
        $this->em->clear();

        $rows = $this->em->getRepository(MediaRendition::class)->findBy([
            'file'   => $this->em->getRepository(MediaAssetFile::class)->findOneBy(['relativePath' => $name]),
            'preset' => RenditionPreset::Thumb->value,
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(RenditionState::Ready, $rows[0]->getState());
        $this->assertNull($rows[0]->getFailureReason());
        $this->assertTrue(is_file($this->mediaRoot().'/'.$rows[0]->getStoragePath()));
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function runIndexCommand(array $options = []): string
    {
        $kernel = self::getContainer()->get(KernelInterface::class);
        $this->assertInstanceOf(KernelInterface::class, $kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('leuchtfeuer:media:index-media'));
        $status = $tester->execute($options);
        $this->assertSame(0, $status, $tester->getDisplay());

        return $tester->getDisplay();
    }

    private function mediaRoot(): string
    {
        $paths = self::getContainer()->get(PathsHelper::class);
        $this->assertInstanceOf(PathsHelper::class, $paths);

        return rtrim($paths->getImagePath(), '/');
    }

    private function mkdirRelative(string $relative): void
    {
        $path = $this->mediaRoot().'/'.$relative;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        foreach (explode('/', $relative) as $i => $segment) {
            $this->createdFolders[] = implode('/', array_slice(explode('/', $relative), 0, $i + 1));
        }
        $this->createdFolders = array_values(array_unique($this->createdFolders));
    }

    private function writePng(string $filename, string $folder = ''): string
    {
        $directory = '' === $folder ? $this->mediaRoot() : $this->mediaRoot().'/'.$folder;
        if (!is_dir($directory)) {
            $this->mkdirRelative($folder);
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);
        file_put_contents($directory.'/'.$filename, $png);
        $relative              = '' === $folder ? $filename : $folder.'/'.$filename;
        $this->createdFiles[]  = $relative;

        return $filename;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function writeJpeg(string $filename, string $folder, int $width, int $height): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required to create large JPEG fixtures');
        }

        $directory = '' === $folder ? $this->mediaRoot() : $this->mediaRoot().'/'.$folder;
        if (!is_dir($directory)) {
            $this->mkdirRelative($folder);
        }

        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 30, 60, 90);
        $this->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagejpeg($image, $directory.'/'.$filename, 90);
        imagedestroy($image);

        $relative             = '' === $folder ? $filename : $folder.'/'.$filename;
        $this->createdFiles[] = $relative;

        return $filename;
    }

    private function removeEmptyDirsUnder(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }

    private function countErrorHandlers(): int
    {
        $handlers = [];

        while (true) {
            $previous = set_error_handler(static fn (): false => false);
            restore_error_handler();

            if (null === $previous) {
                break;
            }

            $handlers[] = $previous;
            restore_error_handler();
        }

        foreach (array_reverse($handlers) as $handler) {
            set_error_handler($handler);
        }

        return count($handlers);
    }

    private function countExceptionHandlers(): int
    {
        $handlers = [];

        while (true) {
            $previous = set_exception_handler(static fn (): null => null);
            restore_exception_handler();

            if (null === $previous) {
                break;
            }

            $handlers[] = $previous;
            restore_exception_handler();
        }

        foreach (array_reverse($handlers) as $handler) {
            set_exception_handler($handler);
        }

        return count($handlers);
    }
}
