<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

final readonly class GeneratedRendition
{
    public function __construct(
        public string $absolutePath,
        public string $relativePath,
        public string $format,
        public int $width,
        public int $height,
        public int $bytes,
    ) {
    }
}
