<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final class FileChecksum
{
    public static function hashFile(string $absolutePath): string
    {
        $hash = hash_file('sha256', $absolutePath);
        if (false === $hash) {
            throw new ImageRejectedException(Translation::ERROR_READ);
        }

        return $hash;
    }

    public static function hashContents(string $contents): string
    {
        return hash('sha256', $contents);
    }
}
