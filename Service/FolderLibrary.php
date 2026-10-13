<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Mautic\CoreBundle\Helper\InputHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\MediaIndexer;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\FolderNode;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFolderRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final readonly class FolderLibrary
{
    public function __construct(
        private MediaPath $paths,
        private MediaAssetFolderRepository $folders,
        private MediaAssetFileRepository $files,
        private MediaIndexer $index,
    ) {
    }

    public function tree(string $homeLabel, bool $allowSvg = false): FolderNode
    {
        $activeFolders = array_values(array_filter(
            $this->folders->findActiveFolders(),
            static fn (MediaAssetFolder $folder): bool => !MediaIndexPath::isHiddenRelativePath($folder->getRelativePath()),
        ));

        /** @var array<string, list<MediaAssetFolder>> $byParentPath */
        $byParentPath = [];
        foreach ($activeFolders as $folder) {
            // Parent comes from the canonical relative path, not the Doctrine association
            // (parent_id can lag until a reconcile run repairs it).
            $parentPath                  = MediaIndexPath::parentRelative($folder->getRelativePath());
            $byParentPath[$parentPath][] = $folder;
        }

        foreach ($byParentPath as $parentPath => $children) {
            usort(
                $children,
                static fn (MediaAssetFolder $a, MediaAssetFolder $b): int => strnatcasecmp($a->getName(), $b->getName()),
            );
            $byParentPath[$parentPath] = $children;
        }

        $files = $this->files->findActiveFiles();
        if (!$allowSvg) {
            // Match the picker list: emails hide SVGs, so folder counts must too.
            $files = array_values(array_filter(
                $files,
                static fn (MediaAssetFile $file): bool => ImageInspector::SVG_MIME !== $file->getMime(),
            ));
        }

        $counts = $this->recursiveImageCounts($files);

        return $this->node($homeLabel, '', $byParentPath, $counts);
    }

    public function create(?string $parentRelative, string $name): FolderNode
    {
        $parent   = $this->paths->resolve($parentRelative);
        $stem     = $this->folderName($name);
        $relative = $this->paths->join($parent['relative'], $stem);
        $absolute = $parent['absolute'].'/'.$stem;

        if (is_dir($absolute)) {
            throw new ImageRejectedException(Translation::ERROR_FOLDER_EXISTS);
        }

        if (!mkdir($absolute, 0775) && !is_dir($absolute)) {
            throw new ImageRejectedException(Translation::ERROR_DIRECTORY);
        }

        $this->index->registerFolder($relative, $stem);

        return new FolderNode($stem, $relative, 0);
    }

    /**
     * @param array<string, list<MediaAssetFolder>> $byParentPath
     * @param array<string, int>                    $counts
     */
    private function node(string $name, string $relative, array $byParentPath, array $counts): FolderNode
    {
        $children = [];
        foreach ($byParentPath[$relative] ?? [] as $folder) {
            $children[] = $this->node(
                $folder->getName(),
                $folder->getRelativePath(),
                $byParentPath,
                $counts,
            );
        }

        return new FolderNode($name, $relative, $counts[$relative] ?? 0, $children);
    }

    /**
     * @param list<MediaAssetFile> $files
     *
     * @return array<string, int>
     */
    private function recursiveImageCounts(array $files): array
    {
        $counts = ['' => 0];
        foreach ($files as $file) {
            ++$counts[''];
            $folderPath = MediaIndexPath::parentRelative($file->getRelativePath());
            while ('' !== $folderPath) {
                $counts[$folderPath] = ($counts[$folderPath] ?? 0) + 1;
                $folderPath          = MediaIndexPath::parentRelative($folderPath);
            }
        }

        return $counts;
    }

    private function folderName(string $name): string
    {
        $stem = pathinfo(InputHelper::transliterateFilename($name), PATHINFO_FILENAME);
        $stem = preg_replace('/-+/', '-', $stem) ?? '';
        $stem = trim($stem, '-');
        if ('' === $stem || str_starts_with($stem, '.')) {
            throw new ImageRejectedException(Translation::ERROR_FOLDER_NAME);
        }

        return substr($stem, 0, 80);
    }
}
