<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

final class IniSize
{
    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value) {
            return 0;
        }

        $unit   = strtolower($value[strlen($value) - 1]);
        $number = (float) $value;

        return match ($unit) {
            'g'     => (int) ($number * 1024 * 1024 * 1024),
            'm'     => (int) ($number * 1024 * 1024),
            'k'     => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    public static function uploadMaxFilesize(): string
    {
        $value = ini_get('upload_max_filesize');

        return false === $value || '' === $value ? '2M' : $value;
    }
}
