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

    public function store(string $directory, string $originalName, string $contents, string $relativeDirectory = '', bool $allowSvg = false): ListedImage
    {
        $inspected = $this->inspector->inspectContents($contents, $allowSvg);
        $this->ensureDirectory($directory);

        $name = $this->uniqueName($directory, $this->stem($originalName), $inspected->extension);
        $path = $directory.'/'.$name;
        if (false === file_put_contents($path, $contents, LOCK_EX)) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        return new ListedImage(
            $name,
            $inspected->width,
            $inspected->height,
            strlen($contents),
            '' === $relativeDirectory ? $name : $relativeDirectory.'/'.$name,
        );
    }

    public function move(MediaPath $paths, string $imageRelative, string $targetFolderRelative, bool $allowSvg = false): ListedImage
    {
        $source = $paths->resolveFile($imageRelative);
        $target = $paths->resolve($targetFolderRelative);

        if ($paths->parentRelative($source['relative']) === $target['relative']) {
            throw new ImageRejectedException(Translation::ERROR_PATH);
        }

        $inspected = $this->inspector->inspectFile($source['absolute'], $allowSvg || str_ends_with(strtolower($source['relative']), '.svg'));
        $stem      = $this->stem(basename($source['relative']));
        $newName   = $this->uniqueName($target['absolute'], $stem, $inspected->extension);
        $dest      = $target['absolute'].'/'.$newName;

        if (!rename($source['absolute'], $dest)) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        $bytes = filesize($dest);

        return new ListedImage(
            $newName,
            $inspected->width,
            $inspected->height,
            false === $bytes ? 0 : $bytes,
            $paths->join($target['relative'], $newName),
        );
    }

    public function storeUploaded(string $directory, UploadedFile $file, string $relativeDirectory = '', bool $allowSvg = false): ListedImage
    {
        $inspected = $this->inspector->inspectFile($file->getPathname(), $allowSvg);
        $this->ensureDirectory($directory);

        $name = $this->uniqueName($directory, $this->stem($file->getClientOriginalName()), $inspected->extension);
        try {
            $moved = $file->move($directory, $name);
        } catch (FileException) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        $bytes = $moved->getSize();

        return new ListedImage(
            $name,
            $inspected->width,
            $inspected->height,
            false === $bytes ? 0 : $bytes,
            '' === $relativeDirectory ? $name : $relativeDirectory.'/'.$name,
        );
    }

    public function replaceUploaded(string $directory, UploadedFile $file, string $relativeDirectory, string $exactName, bool $allowSvg = false): ListedImage
    {
        $inspected = $this->inspector->inspectFile($file->getPathname(), $allowSvg);
        $this->ensureDirectory($directory);

        $dest = $directory.'/'.$exactName;
        if (is_file($dest) && !unlink($dest)) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        try {
            $file->move($directory, $exactName);
        } catch (FileException) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        $bytes = filesize($dest);

        return new ListedImage(
            $exactName,
            $inspected->width,
            $inspected->height,
            false === $bytes ? 0 : $bytes,
            '' === $relativeDirectory ? $exactName : $relativeDirectory.'/'.$exactName,
        );
    }

    public function storeContentsAs(string $directory, string $exactName, string $contents, string $relativeDirectory = '', bool $allowSvg = false): ListedImage
    {
        $inspected = $this->inspector->inspectContents($contents, $allowSvg);
        $this->ensureDirectory($directory);

        $path = $directory.'/'.$exactName;
        if (false === file_put_contents($path, $contents, LOCK_EX)) {
            throw new ImageRejectedException(Translation::ERROR_STORE);
        }

        return new ListedImage(
            $exactName,
            $inspected->width,
            $inspected->height,
            strlen($contents),
            '' === $relativeDirectory ? $exactName : $relativeDirectory.'/'.$exactName,
        );
    }

    public function intendedUploadedName(UploadedFile $file, bool $allowSvg = false): string
    {
        $inspected = $this->inspector->inspectFile($file->getPathname(), $allowSvg);

        return $this->stem($file->getClientOriginalName()).'.'.$inspected->extension;
    }

    public function intendedContentsName(string $originalName, string $contents, bool $allowSvg = false): string
    {
        $inspected = $this->inspector->inspectContents($contents, $allowSvg);

        return $this->stem($originalName).'.'.$inspected->extension;
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
