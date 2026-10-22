<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\UploadConflict;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\ConflictType;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;

final readonly class UploadConflictDetector
{
    public function __construct(private MediaAssetFileRepository $files)
    {
    }

    public function detect(string $absoluteTempPath, string $intendedName, string $folderRelative): ?UploadConflict
    {
        $checksum     = FileChecksum::hashFile($absoluteTempPath);
        $intendedPath = '' === $folderRelative ? $intendedName : $folderRelative.'/'.$intendedName;

        $byChecksum = $this->files->findOldestActiveByChecksum($checksum);
        if ($byChecksum instanceof MediaAssetFile) {
            return new UploadConflict(
                ConflictType::ChecksumDuplicate,
                $checksum,
                $intendedName,
                $intendedPath,
                $byChecksum,
            );
        }

        $byPath = $this->files->findOneByRelativePath($intendedPath);
        if ($byPath instanceof MediaAssetFile && $byPath->isActive()) {
            return new UploadConflict(
                ConflictType::NameClash,
                $checksum,
                $intendedName,
                $intendedPath,
                $byPath,
            );
        }

        return null;
    }
}
