<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Mautic\CoreBundle\Helper\InputHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\FolderNode;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final readonly class FolderLibrary
{
    public function __construct(
        private MediaPath $paths,
        private ImageInspector $inspector,
    ) {
    }

    public function tree(string $homeLabel): FolderNode
    {
        $root = $this->paths->root();
        if (!is_dir($root)) {
            return new FolderNode($homeLabel, '', 0);
        }

        return $this->node($homeLabel, '', $root);
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

        return new FolderNode($stem, $relative, 0);
    }

    private function node(string $name, string $relative, string $absolute): FolderNode
    {
        $children = [];
        $entries  = scandir($absolute);
        if (false === $entries) {
            return new FolderNode($name, $relative, 0);
        }

        $directories = [];
        foreach ($entries as $entry) {
            if ($this->isHidden($entry)) {
                continue;
            }
            $path = $absolute.'/'.$entry;
            if (is_dir($path)) {
                $directories[] = $entry;
            }
        }
        natcasesort($directories);

        foreach ($directories as $entry) {
            $childRelative = $this->paths->join($relative, $entry);
            $children[]    = $this->node($entry, $childRelative, $absolute.'/'.$entry);
        }

        return new FolderNode($name, $relative, $this->countImages($absolute), $children);
    }

    private function countImages(string $directory): int
    {
        $count   = 0;
        $entries = scandir($directory);
        if (false === $entries) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($this->isHidden($entry)) {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $count += $this->countImages($path);
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            try {
                $this->inspector->inspectFile($path);
            } catch (ImageRejectedException) {
                continue;
            }
            ++$count;
        }

        return $count;
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

    private function isHidden(string $entry): bool
    {
        return str_starts_with($entry, '.');
    }
}
