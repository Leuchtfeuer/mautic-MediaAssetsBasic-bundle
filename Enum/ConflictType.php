<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum;

enum ConflictType: string
{
    case ChecksumDuplicate = 'checksum_duplicate';
    case NameClash         = 'name_clash';
}
