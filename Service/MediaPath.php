<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final readonly class MediaPath
{
    public function __construct(private ImageUrls $urls)
    {
    }

    public function root(): string
    {
        return $this->urls->directory();
    }

    /**
     * @return array{absolute: string, relative: string}
     */
    public function resolveFile(string $relative): array
    {
        $normalized = $this->normalize($relative);
        if ('' === $normalized) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        $this->ensureRoot();

        $absolute = $this->root().'/'.$normalized;
        if (!is_file($absolute)) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        $realRoot     = realpath($this->root());
        $realAbsolute = realpath($absolute);
        if (false === $realRoot || false === $realAbsolute || !str_starts_with($realAbsolute, $realRoot)) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        return [
            'absolute' => $realAbsolute,
            'relative' => $normalized,
        ];
    }

    public function parentRelative(string $fileRelative): string
    {
        $normalized   = $this->normalize($fileRelative);
        $position     = strrpos($normalized, '/');

        return false === $position ? '' : substr($normalized, 0, $position);
    }

    /**
     * @return array{absolute: string, relative: string}
     */
    public function resolve(?string $relative): array
    {
        $normalized = $this->normalize($relative);
        $this->ensureRoot();

        $absolute = '' === $normalized ? $this->root() : $this->root().'/'.$normalized;
        if (!is_dir($absolute)) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        $realRoot     = realpath($this->root());
        $realAbsolute = realpath($absolute);
        if (false === $realRoot || false === $realAbsolute || !str_starts_with($realAbsolute, $realRoot)) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        return [
            'absolute' => $realAbsolute,
            'relative' => $normalized,
        ];
    }

    public function join(string $relativeDirectory, string $name): string
    {
        $directory = $this->normalize($relativeDirectory);

        return '' === $directory ? $name : $directory.'/'.$name;
    }

    public function normalize(?string $relative): string
    {
        if (null === $relative) {
            return '';
        }

        $relative = str_replace('\\', '/', trim($relative));
        $relative = trim($relative, '/');
        if ('' === $relative) {
            return '';
        }

        if (str_contains($relative, "\0")) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        $parts = [];
        foreach (explode('/', $relative) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part || str_starts_with($part, '.')) {
                throw new ImageRejectedException(Translation::ERROR_PATH);
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function ensureRoot(): void
    {
        if (is_dir($this->root())) {
            return;
        }

        if (!mkdir($this->root(), 0775, true) && !is_dir($this->root())) {
            throw new ImageRejectedException(Translation::ERROR_DIRECTORY);
        }
    }
}
