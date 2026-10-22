<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\InspectedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\MimeTypes;

final class ImageInspector
{
    public const SVG_MIME = 'image/svg+xml';

    /** @var array<string, string> */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function inspectFile(string $path, bool $allowSvg = false): InspectedImage
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        try {
            $mime = MimeTypes::getDefault()->guessMimeType($path);
        } catch (InvalidArgumentException) {
            $mime = null;
        }

        $extensions = $this->extensions($allowSvg);
        if (is_string($mime) && isset($extensions[$mime])) {
            if (self::SVG_MIME === $mime) {
                return $this->inspectSvg($contents);
            }

            $size = @getimagesize($path);
            if (false === $size) {
                throw new ImageRejectedException(Translation::ERROR_TYPE);
            }

            return new InspectedImage($mime, $extensions[$mime], (int) $size[0], (int) $size[1]);
        }

        if ($allowSvg && ($this->looksLikeSvgPath($path) || $this->looksLikeSvgContents($contents))) {
            return $this->inspectSvg($contents);
        }

        throw new ImageRejectedException(Translation::ERROR_TYPE);
    }

    public function inspectContents(string $contents, bool $allowSvg = false): InspectedImage
    {
        $finfo      = new \finfo(FILEINFO_MIME_TYPE);
        $mime       = $finfo->buffer($contents);
        $extensions = $this->extensions($allowSvg);

        if (is_string($mime) && isset($extensions[$mime])) {
            if (self::SVG_MIME === $mime) {
                return $this->inspectSvg($contents);
            }

            $size = @getimagesizefromstring($contents);
            if (false === $size) {
                throw new ImageRejectedException(Translation::ERROR_TYPE);
            }

            return new InspectedImage($mime, $extensions[$mime], (int) $size[0], (int) $size[1]);
        }

        if ($allowSvg && $this->looksLikeSvgContents($contents)) {
            return $this->inspectSvg($contents);
        }

        throw new ImageRejectedException(Translation::ERROR_TYPE);
    }

    /**
     * @return array<string, string>
     */
    private function extensions(bool $allowSvg): array
    {
        if (!$allowSvg) {
            return self::EXTENSIONS;
        }

        return self::EXTENSIONS + [self::SVG_MIME => 'svg'];
    }

    private function inspectSvg(string $contents): InspectedImage
    {
        $this->assertSafeSvg($contents);
        [$width, $height] = $this->svgDimensions($contents);

        return new InspectedImage(self::SVG_MIME, 'svg', $width, $height);
    }

    private function assertSafeSvg(string $contents): void
    {
        if (!$this->looksLikeSvgContents($contents)) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        // DTD entity expansion can hide payloads (e.g. <!ENTITY x "<script>">).
        if (1 === preg_match('/<!ENTITY\b|<!DOCTYPE\b/i', $contents)) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        $normalized = $this->normalizeSvgForSafetyCheck($contents);
        // Collapse control/whitespace so java&#9;script: / java\nscript: still match.
        $compact    = preg_replace('/[\x00-\x20]+/', '', $normalized) ?? $normalized;

        if (
            1 === preg_match('/<\s*(?:\w+:)?script\b/', $normalized)
            || 1 === preg_match('/<\s*(?:\w+:)?foreignobject\b/', $normalized)
            || str_contains($compact, 'javascript:')
            || str_contains($compact, 'data:text/html')
            || str_contains($normalized, '@import')
            || 1 === preg_match('/<\?(?:php|=|\s)/', $normalized)
            || 1 === preg_match('/\son[a-z]+\s*=/', $normalized)
        ) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }
    }

    private function normalizeSvgForSafetyCheck(string $contents): string
    {
        $decoded = str_replace("\0", '', $contents);
        for ($i = 0; $i < 3; ++$i) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        return strtolower($decoded);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function svgDimensions(string $contents): array
    {
        $width  = 0;
        $height = 0;

        if (preg_match('/\bwidth=["\']?\s*(\d+(?:\.\d+)?)/i', $contents, $match)) {
            $width = (int) $match[1];
        }
        if (preg_match('/\bheight=["\']?\s*(\d+(?:\.\d+)?)/i', $contents, $match)) {
            $height = (int) $match[1];
        }
        if (preg_match('/viewBox=["\']?\s*[\d.]+\s+[\d.]+\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/i', $contents, $match)) {
            $width  = (int) $match[1];
            $height = (int) $match[2];
        }

        return [$width, $height];
    }

    private function looksLikeSvgPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.svg');
    }

    private function looksLikeSvgContents(string $contents): bool
    {
        return 1 === preg_match('/<svg[\s>]/i', $contents);
    }
}
