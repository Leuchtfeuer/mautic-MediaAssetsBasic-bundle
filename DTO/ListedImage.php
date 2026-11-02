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
        public string $path = '',
        public ?string $thumbPath = null,
        public ?string $insertPath = null,
    ) {
    }

    /**
     * @return array{name: string, path: string, width: int, height: int, bytes: int, thumbPath: ?string, insertPath: string}
     */
    public function toArray(): array
    {
        $path = '' !== $this->path ? $this->path : $this->name;

        return [
            'name'       => $this->name,
            'path'       => $path,
            'width'      => $this->width,
            'height'     => $this->height,
            'bytes'      => $this->bytes,
            'thumbPath'  => $this->thumbPath,
            'insertPath' => $this->insertPath ?? $path,
        ];
    }
}
