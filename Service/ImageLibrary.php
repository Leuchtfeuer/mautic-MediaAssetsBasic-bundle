<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaRenditionRepository;

final readonly class ImageLibrary
{
    public function __construct(
        private MediaAssetFileRepository $files,
        private MediaRenditionRepository $renditions,
    ) {
    }

    /**
     * @return array{images: list<ListedImage>, total: int}
     */
    public function listImages(
        string $folderRelative,
        int $page,
        int $limit,
        bool $allowSvg = false,
        string $query = '',
        bool $everywhere = false,
    ): array {
        $page  = max(1, $page);
        $limit = max(1, $limit);

        if (FilenameSearch::isActive($query)) {
            $searchRoot = $everywhere ? '' : $folderRelative;
            $listed     = $this->files->searchInTree(
                $searchRoot,
                $query,
                $folderRelative,
                $page,
                $limit,
                $allowSvg,
            );
        } else {
            $listed = $this->files->listInFolder($folderRelative, $page, $limit, $allowSvg);
        }

        $ids = array_values(array_filter(array_map(
            static fn (MediaAssetFile $file): ?int => $file->getId(),
            $listed['images'],
        )));
        $ready = $this->renditions->mapReadyByFileIds($ids);

        return [
            'images' => array_map(
                fn (MediaAssetFile $file): ListedImage => $this->toListedImage($file, $ready[$file->getId()] ?? []),
                $listed['images'],
            ),
            'total' => $listed['total'],
        ];
    }

    public function findListed(string $relativePath): ?ListedImage
    {
        $file = $this->files->findOneByRelativePath($relativePath);

        return $file instanceof MediaAssetFile ? $this->toListedImage($file) : null;
    }

    /**
     * @param array<string, MediaRendition> $readyByPreset
     */
    public function toListedImage(MediaAssetFile $file, array $readyByPreset = []): ListedImage
    {
        if ([] === $readyByPreset && null !== $file->getId()) {
            $readyByPreset = $this->renditions->mapReadyByFileIds([$file->getId()])[$file->getId()] ?? [];
        }

        $path      = $file->getRelativePath();
        $thumb     = $readyByPreset[RenditionPreset::Thumb->value] ?? null;
        $standard  = $readyByPreset[RenditionPreset::Standard->value] ?? null;
        $isSvg     = ImageInspector::SVG_MIME === $file->getMime();

        return new ListedImage(
            $file->getFilename(),
            $file->getWidth(),
            $file->getHeight(),
            $file->getBytes(),
            $path,
            $isSvg ? $path : ($thumb instanceof MediaRendition ? $thumb->getStoragePath() : null),
            $isSvg ? $path : ($standard instanceof MediaRendition ? $standard->getStoragePath() : $path),
        );
    }
}
