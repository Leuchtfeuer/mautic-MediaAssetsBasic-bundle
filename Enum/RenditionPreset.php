<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum;

enum RenditionPreset: string
{
    case Standard = 'standard';
    case Thumb    = 'thumb';

    public const STANDARD_LONG_EDGE = 1600;
    public const THUMB_LONG_EDGE    = 400;
    public const STANDARD_QUALITY   = 82;
    public const THUMB_QUALITY      = 75;

    public function longEdge(): int
    {
        return match ($this) {
            self::Standard => self::STANDARD_LONG_EDGE,
            self::Thumb    => self::THUMB_LONG_EDGE,
        };
    }

    public function quality(): int
    {
        return match ($this) {
            self::Standard => self::STANDARD_QUALITY,
            self::Thumb    => self::THUMB_QUALITY,
        };
    }
}
