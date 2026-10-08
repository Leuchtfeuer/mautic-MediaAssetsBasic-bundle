<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\InspectedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;

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
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new ImageRejectedException('The file could not be read.');
        }

        return $this->inspectContents($contents);
    }

    public function inspectContents(string $contents): InspectedImage
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($contents);
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) {
            throw new ImageRejectedException('Only JPEG, PNG, GIF and WebP images are allowed.');
        }

        $size = @getimagesizefromstring($contents);
        if (false === $size) {
            throw new ImageRejectedException('Only JPEG, PNG, GIF and WebP images are allowed.');
        }

        return new InspectedImage($mime, self::EXTENSIONS[$mime], (int) $size[0], (int) $size[1]);
    }
}
