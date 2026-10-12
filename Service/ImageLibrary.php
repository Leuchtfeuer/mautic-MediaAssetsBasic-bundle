<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use Symfony\Component\Finder\Finder;

final readonly class ImageLibrary
{
    public function __construct(private ImageInspector $inspector)
    {
    }

    /**
     * @return array{images: list<ListedImage>, total: int}
     */
    public function listImages(string $directory, int $page, int $limit, string $relativeDirectory = '', bool $allowSvg = false): array
    {
        $page  = max(1, $page);
        $limit = max(1, $limit);
        if (!is_dir($directory)) {
            return ['images' => [], 'total' => 0];
        }

        $finder = new Finder();
        $finder->files()->in($directory)->depth('== 0')->sortByModifiedTime()->reverseSorting();

        $images = [];
        $total  = 0;
        $offset = ($page - 1) * $limit;
        foreach ($finder as $file) {
            try {
                $inspected = $this->inspector->inspectFile($file->getPathname(), $allowSvg);
            } catch (ImageRejectedException) {
                continue;
            }

            $bytes = $file->getSize();
            if (false === $bytes) {
                continue;
            }

            if ($total >= $offset && count($images) < $limit) {
                $name     = $file->getFilename();
                $path     = '' === $relativeDirectory ? $name : $relativeDirectory.'/'.$name;
                $images[] = new ListedImage(
                    $name,
                    $inspected->width,
                    $inspected->height,
                    $bytes,
                    $path,
                );
            }

            ++$total;
        }

        return [
            'images' => $images,
            'total'  => $total,
        ];
    }
}
