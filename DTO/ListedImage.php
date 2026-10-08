<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

final readonly class ListedImage
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public int $bytes,
    ) {
    }

    /**
     * @return array{name: string, width: int, height: int, bytes: int}
     */
    public function toArray(): array
    {
        return [
            'name'   => $this->name,
            'width'  => $this->width,
            'height' => $this->height,
            'bytes'  => $this->bytes,
        ];
    }
}
