<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\InspectedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final class ImageInspector
{
    /** @var array<string, string> */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function inspectFile(string $path): InspectedImage
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        $size = @getimagesize($path);
        if (false === $size) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        return new InspectedImage($mime, self::EXTENSIONS[$mime], (int) $size[0], (int) $size[1]);
    }

    public function inspectContents(string $contents): InspectedImage
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($contents);
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        $size = @getimagesizefromstring($contents);
        if (false === $size) {
            throw new ImageRejectedException(Translation::ERROR_TYPE);
        }

        return new InspectedImage($mime, self::EXTENSIONS[$mime], (int) $size[0], (int) $size[1]);
    }
}
