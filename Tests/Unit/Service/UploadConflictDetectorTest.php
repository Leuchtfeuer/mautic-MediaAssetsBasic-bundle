<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\ConflictType;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FileChecksum;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\UploadConflictDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UploadConflictDetectorTest extends TestCase
{
    /** @var MediaAssetFileRepository&MockObject */
    private MockObject $files;

    private string $tempFile = '';

    protected function setUp(): void
    {
        $this->files    = $this->createMock(MediaAssetFileRepository::class);
        $path           = tempnam(sys_get_temp_dir(), 'lf-conflict');
        $this->assertIsString($path);
        $this->tempFile = $path;
        file_put_contents($this->tempFile, 'duplicate-bytes');
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tempFile && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testChecksumDuplicateWinsOverNameClash(): void
    {
        $checksumExisting = (new MediaAssetFile())
            ->setRelativePath('brand/logo.png')
            ->setFilename('logo.png')
            ->setChecksum(FileChecksum::hashFile($this->tempFile))
            ->setDateAdded(new \DateTimeImmutable('2024-01-01'));
        $nameExisting = (new MediaAssetFile())
            ->setRelativePath('campaigns/logo.png')
            ->setFilename('logo.png');

        $this->files->method('findOldestActiveByChecksum')->willReturn($checksumExisting);
        $this->files->method('findOneByRelativePath')->willReturn($nameExisting);

        $conflict = (new UploadConflictDetector($this->files))->detect($this->tempFile, 'logo.png', 'campaigns');

        $this->assertInstanceOf(\MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\UploadConflict::class, $conflict);
        $this->assertSame(ConflictType::ChecksumDuplicate, $conflict->type);
        $this->assertSame('brand/logo.png', $conflict->existing->getRelativePath());
    }

    public function testNameClashWhenChecksumIsUnique(): void
    {
        $existing = (new MediaAssetFile())
            ->setRelativePath('campaigns/logo.png')
            ->setFilename('logo.png')
            ->setChecksum('different');

        $this->files->method('findOldestActiveByChecksum')->willReturn(null);
        $this->files->method('findOneByRelativePath')->with('campaigns/logo.png')->willReturn($existing);

        $conflict = (new UploadConflictDetector($this->files))->detect($this->tempFile, 'logo.png', 'campaigns');

        $this->assertInstanceOf(\MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\UploadConflict::class, $conflict);
        $this->assertSame(ConflictType::NameClash, $conflict->type);
    }

    public function testIgnoresSoftDeletedChecksumMatchesViaRepositoryContract(): void
    {
        $this->files->method('findOldestActiveByChecksum')->willReturn(null);
        $this->files->method('findOneByRelativePath')->willReturn(null);

        $this->assertNotInstanceOf(\MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\UploadConflict::class, (new UploadConflictDetector($this->files))->detect($this->tempFile, 'logo.png', ''));
    }
}
