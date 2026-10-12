<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Functional\Command;

use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;
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
        }
        foreach (array_reverse($this->createdFolders) as $relative) {
            $path = $directory.'/'.$relative;
            if (is_dir($path)) {
                rmdir($path);
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

    /**
     * @param array<string, bool|string> $options
     */
    private function runIndexCommand(array $options = []): string
    {
        $kernel = self::getContainer()->get(KernelInterface::class);
        $this->assertInstanceOf(KernelInterface::class, $kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('mautic:media-assets-basic:index-media'));
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
