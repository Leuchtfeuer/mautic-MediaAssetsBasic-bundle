<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

final readonly class InspectedImage
{
    public function __construct(
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
    ) {
    }
}
