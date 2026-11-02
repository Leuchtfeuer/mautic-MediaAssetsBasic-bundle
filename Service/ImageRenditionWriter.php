<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\GeneratedRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

/**
 * Writes a single rendition file from a master image (Imagick preferred, GD fallback).
 */
final class ImageRenditionWriter
{
    public function __construct(
        private readonly RenditionPaths $renditionPaths,
        private readonly ImageInspector $inspector,
    ) {
    }

    public function supportsImagick(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    public function supportsGd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * @return GeneratedRendition|null Null when this preset should not be written (e.g. no upscale for standard).
     */
    public function write(string $sourceAbsolute, string $sourceRelative, RenditionPreset $preset, bool $animatedGif): ?GeneratedRendition
    {
        if ($animatedGif && RenditionPreset::Standard === $preset) {
            return null;
        }

        $inspected = $this->inspector->inspectFile($sourceAbsolute, false);
        if (ImageInspector::SVG_MIME === $inspected->mime) {
            return null;
        }

        $longEdge = max($inspected->width, $inspected->height);
        if (RenditionPreset::Standard === $preset && $longEdge <= $preset->longEdge()) {
            return null;
        }

        $relative = $this->renditionPaths->relativeFor($sourceRelative, $preset);
        $absolute = $this->renditionPaths->absoluteFor($sourceRelative, $preset);
        $this->ensureDirectory(dirname($absolute));

        if ($this->supportsImagick()) {
            $this->writeWithImagick($sourceAbsolute, $absolute, $preset, $animatedGif, $inspected->mime);
        } elseif ($this->supportsGd()) {
            $this->writeWithGd($sourceAbsolute, $absolute, $preset, $inspected->mime);
        } else {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        $size  = @getimagesize($absolute);
        $bytes = @filesize($absolute);
        if (false === $size || false === $bytes) {
            @unlink($absolute);
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        return new GeneratedRendition(
            $absolute,
            $relative,
            $inspected->extension,
            (int) $size[0],
            (int) $size[1],
            $bytes,
        );
    }

    public function isAnimatedGif(string $absolutePath, string $mime): bool
    {
        if ('image/gif' !== $mime) {
            return false;
        }

        if ($this->supportsImagick()) {
            $image = new \Imagick($absolutePath);

            try {
                return $image->getNumberImages() > 1;
            } finally {
                $image->clear();
                $image->destroy();
            }
        }

        $contents = @file_get_contents($absolutePath);
        if (!is_string($contents)) {
            return false;
        }

        // Multiple graphic-control extension blocks strongly indicate animation.
        return substr_count($contents, "\x00\x21\xF9\x04") > 1;
    }

    private function writeWithImagick(
        string $sourceAbsolute,
        string $absolute,
        RenditionPreset $preset,
        bool $animatedGif,
        string $mime,
    ): void {
        $image = new \Imagick($sourceAbsolute);

        try {
            if ($animatedGif || $image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                $image->setIteratorIndex(0);
                $frame = $image->getImage();
                $image->clear();
                $image->destroy();
                $image = $frame;
            }

            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            } else {
                $orientation = $image->getImageOrientation();
                $this->applyImagickOrientation($image, $orientation);
                $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            }

            if (\Imagick::COLORSPACE_SRGB !== $image->getImageColorspace()) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }

            $image->stripImage();

            $width  = $image->getImageWidth();
            $height = $image->getImageHeight();
            $target = $this->targetDimensions($width, $height, $preset->longEdge());
            if ($target['width'] !== $width || $target['height'] !== $height) {
                $image->resizeImage(
                    $target['width'],
                    $target['height'],
                    \Imagick::FILTER_LANCZOS,
                    1,
                    true,
                );
            }

            $image->setImageFormat($this->imagickFormat($mime));
            if ('image/jpeg' === $mime) {
                $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality($preset->quality());
            } elseif ('image/webp' === $mime) {
                $image->setImageCompressionQuality($preset->quality());
            } elseif ('image/png' === $mime) {
                $image->setOption('png:exclude-chunk', 'all');
            }

            $image->writeImage($absolute);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private function writeWithGd(string $sourceAbsolute, string $absolute, RenditionPreset $preset, string $mime): void
    {
        $source = $this->gdCreate($sourceAbsolute, $mime);
        if (false === $source) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        try {
            $source = $this->gdApplyExifOrientation($source, $sourceAbsolute, $mime);
            $width  = imagesx($source);
            $height = imagesy($source);
            if (false === $width || false === $height) {
                throw new ImageRejectedException(Translation::ERROR_TYPE);
            }

            $target = $this->targetDimensions($width, $height, $preset->longEdge());
            $dest   = imagecreatetruecolor($target['width'], $target['height']);
            if (false === $dest) {
                throw new ImageRejectedException(Translation::ERROR_TYPE);
            }

            try {
                if (\in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
                    imagealphablending($dest, false);
                    imagesavealpha($dest, true);
                    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
                    if (false !== $transparent) {
                        imagefilledrectangle($dest, 0, 0, $target['width'], $target['height'], $transparent);
                    }
                }

                imagecopyresampled(
                    $dest,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $target['width'],
                    $target['height'],
                    $width,
                    $height,
                );

                $ok = match ($mime) {
                    'image/jpeg' => imagejpeg($dest, $absolute, $preset->quality()),
                    'image/png'  => imagepng($dest, $absolute, $this->pngCompression($preset->quality())),
                    'image/gif'  => imagegif($dest, $absolute),
                    'image/webp' => function_exists('imagewebp') && imagewebp($dest, $absolute, $preset->quality()),
                    default      => false,
                };

                if (true !== $ok) {
                    throw new ImageRejectedException(Translation::ERROR_TYPE);
                }
            } finally {
                imagedestroy($dest);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * @return array{width: int, height: int}
     */
    private function targetDimensions(int $width, int $height, int $longEdge): array
    {
        $current = max($width, $height);
        if ($current <= $longEdge || $current < 1) {
            return ['width' => max(1, $width), 'height' => max(1, $height)];
        }

        $scale = $longEdge / $current;

        return [
            'width'  => max(1, (int) round($width * $scale)),
            'height' => max(1, (int) round($height * $scale)),
        ];
    }

    private function imagickFormat(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => throw new ImageRejectedException(Translation::ERROR_TYPE),
        };
    }

    private function applyImagickOrientation(\Imagick $image, int $orientation): void
    {
        switch ($orientation) {
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
            case \Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case \Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage('#000', -90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage('#000', 90);
                break;
        }
    }

    private function gdCreate(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
    }

    private function gdApplyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ('image/jpeg' !== $mime || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        if (!is_array($exif) || !isset($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];
        $rotated     = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => false,
        };

        if (false === $rotated) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function pngCompression(int $quality): int
    {
        // GD png compression 0 (none) .. 9 (max). Map JPEG-like quality roughly.
        return max(0, min(9, (int) round((100 - $quality) / 10)));
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ImageRejectedException(Translation::ERROR_DIRECTORY);
        }
    }
}
