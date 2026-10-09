<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

final readonly class FolderNode
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $name,
        public string $path,
        public int $count,
        public array $children = [],
    ) {
    }

    /**
     * @return array{name: string, path: string, count: int, children: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'path'     => $this->path,
            'count'    => $this->count,
            'children' => array_map(static fn (self $child): array => $child->toArray(), $this->children),
        ];
    }
}
