<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\ConflictType;

final readonly class UploadConflict
{
    public function __construct(
        public ConflictType $type,
        public string $checksum,
        public string $intendedName,
        public string $intendedPath,
        public MediaAssetFile $existing,
    ) {
    }
}
