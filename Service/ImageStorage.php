<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Mautic\CoreBundle\Helper\InputHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ImageStorage
{
    public function __construct(private ImageInspector $inspector)
    {
    }

    public function store(string $directory, string $originalName, string $contents): ListedImage
    {
        $inspected = $this->inspector->inspectContents($contents);
        $this->ensureDirectory($directory);

        $name = $this->uniqueName($directory, $this->stem($originalName), $inspected->extension);
        $path = $directory.'/'.$name;
        if (false === file_put_contents($path, $contents, LOCK_EX)) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        return new ListedImage($name, $inspected->width, $inspected->height, strlen($contents));
    }

    public function storeUploaded(string $directory, UploadedFile $file): ListedImage
    {
        $inspected = $this->inspector->inspectFile($file->getPathname());
        $this->ensureDirectory($directory);

        $name = $this->uniqueName($directory, $this->stem($file->getClientOriginalName()), $inspected->extension);
        try {
            $moved = $file->move($directory, $name);
        } catch (FileException) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        $bytes = $moved->getSize();

        return new ListedImage($name, $inspected->width, $inspected->height, false === $bytes ? 0 : $bytes);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ImageRejectedException(Translation::ERROR_DIRECTORY);
        }
    }

    private function stem(string $originalName): string
    {
        $stem = pathinfo(InputHelper::transliterateFilename($originalName), PATHINFO_FILENAME);
        $stem = preg_replace('/-+/', '-', $stem) ?? '';
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
