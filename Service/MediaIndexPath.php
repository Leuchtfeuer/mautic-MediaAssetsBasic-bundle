<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

final class MediaIndexPath
{
    public static function isHiddenEntry(string $name): bool
    {
        return str_starts_with($name, '.') || RenditionPaths::isRenditionsEntry($name);
    }

    public static function isHiddenRelativePath(string $relativePath): bool
    {
        if ('' === $relativePath) {
            return false;
        }

        if (RenditionPaths::isRenditionsRelativePath($relativePath)) {
            return true;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if (self::isHiddenEntry($segment)) {
                return true;
            }
        }

        return false;
    }

    public static function parentRelative(string $relativePath): string
    {
        $position = strrpos($relativePath, '/');

        return false === $position ? '' : substr($relativePath, 0, $position);
    }

    public static function filename(string $relativePath): string
    {
        $position = strrpos($relativePath, '/');

        return false === $position ? $relativePath : substr($relativePath, $position + 1);
    }

    public static function folderNameFromRelative(string $relativePath): string
    {
        return self::filename($relativePath);
    }
}
