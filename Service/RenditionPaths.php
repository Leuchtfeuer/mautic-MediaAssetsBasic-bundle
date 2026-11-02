<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;

final readonly class RenditionPaths
{
    public const ROOT = '_renditions';

    public function __construct(private MediaPath $paths)
    {
    }

    public function rootAbsolute(): string
    {
        return $this->paths->root().'/'.self::ROOT;
    }

    public function relativeFor(string $originalRelative, RenditionPreset $preset): string
    {
        return self::relativePathFor($originalRelative, $preset);
    }

    public function absoluteFor(string $originalRelative, RenditionPreset $preset): string
    {
        return $this->paths->root().'/'.$this->relativeFor($originalRelative, $preset);
    }

    public static function relativePathFor(string $originalRelative, RenditionPreset $preset): string
    {
        $originalRelative = str_replace('\\', '/', $originalRelative);
        $directory        = MediaIndexPath::parentRelative($originalRelative);
        $filename         = MediaIndexPath::filename($originalRelative);
        $extensionPos     = strrpos($filename, '.');
        $basename         = false === $extensionPos ? $filename : substr($filename, 0, $extensionPos);
        $extension        = false === $extensionPos ? '' : substr($filename, $extensionPos);
        $derivedName      = $basename.'_'.$preset->longEdge().$extension;
        $under            = '' === $directory ? $derivedName : $directory.'/'.$derivedName;

        return self::ROOT.'/'.$under;
    }

    public static function isRenditionsEntry(string $name): bool
    {
        return self::ROOT === $name;
    }

    public static function isRenditionsRelativePath(string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        return self::ROOT === $relativePath || str_starts_with($relativePath, self::ROOT.'/');
    }
}
