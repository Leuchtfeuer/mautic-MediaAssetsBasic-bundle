<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;

final readonly class ImageStorage
{
    public function __construct(private ImageInspector $inspector)
    {
    }

    public function store(string $directory, string $originalName, string $contents): ListedImage
    {
        $inspected = $this->inspector->inspectContents($contents);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ImageRejectedException('The image directory could not be created.');
        }

        $name = $this->uniqueName($directory, $this->stem($originalName), $inspected->extension);
        $path = $directory.'/'.$name;
        if (false === file_put_contents($path, $contents, LOCK_EX)) {
            throw new ImageRejectedException('The image could not be stored.');
        }

        return new ListedImage($name, $inspected->width, $inspected->height, strlen($contents));
    }

    private function stem(string $originalName): string
    {
        $stem = pathinfo($originalName, PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem) ?? '';
        $stem = trim($stem, '-');
        if ('' === $stem) {
            $stem = 'image';
        }

        return substr($stem, 0, 80);
    }

    private function uniqueName(string $directory, string $stem, string $extension): string
    {
        $name = $stem.'.'.$extension;
        $i    = 1;
        while (is_file($directory.'/'.$name)) {
            $name = $stem.'-'.$i.'.'.$extension;
            ++$i;
        }

        return $name;
    }
}
