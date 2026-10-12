<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;

final readonly class ImageLibrary
{
    public function __construct(private MediaAssetFileRepository $files)
    {
    }

    /**
     * @return array{images: list<ListedImage>, total: int}
     */
    public function listImages(string $folderRelative, int $page, int $limit, bool $allowSvg = false): array
    {
        $page   = max(1, $page);
        $limit  = max(1, $limit);
        $listed = $this->files->listInFolder($folderRelative, $page, $limit, $allowSvg);

        return [
            'images' => array_map(
                static fn (MediaAssetFile $file): ListedImage => new ListedImage(
                    $file->getFilename(),
                    $file->getWidth(),
                    $file->getHeight(),
                    $file->getBytes(),
                    $file->getRelativePath(),
                ),
                $listed['images'],
            ),
            'total' => $listed['total'],
        ];
    }
}
