<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\MediaIndexer;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\MediaIndexRunSummary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFolderRepository;

final class MediaIndex implements MediaIndexer
{
    private bool $dryRun = false;

    /** @var array<string, true> */
    private array $seenFilePaths = [];

    /** @var array<string, true> */
    private array $seenFolderPaths = [];

    /** @var array<string, MediaAssetFolder> */
    private array $folderCache = [];

    /** @var array<string, true> */
    private array $seenChecksums = [];

    public function __construct(
        private readonly MediaPath $paths,
        private readonly ImageInspector $inspector,
        private readonly MediaAssetFolderRepository $folders,
        private readonly MediaAssetFileRepository $files,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registerUploaded(ListedImage $image, bool $allowSvg): void
    {
        $relative = '' !== $image->path ? $image->path : $image->name;
        $absolute = $this->paths->root().'/'.$relative;
        if (!is_file($absolute)) {
            return;
        }

        $this->dryRun      = false;
        $this->folderCache = [];
        $this->indexFileAtPath(
            $absolute,
            $relative,
            $allowSvg || str_ends_with(strtolower($relative), '.svg'),
        );
        $this->entityManager->flush();
    }

    public function registerMoved(string $oldRelative, ListedImage $image, bool $allowSvg): void
    {
        $newRelative = '' !== $image->path ? $image->path : $image->name;
        $absolute    = $this->paths->root().'/'.$newRelative;
        if (!is_file($absolute)) {
            return;
        }

        $this->dryRun      = false;
        $this->folderCache = [];
        $existing          = $this->files->findOneByRelativePath($oldRelative);
        if (!$existing instanceof MediaAssetFile) {
            $existing = $this->files->findOneByRelativePath($newRelative);
        }

        try {
            $inspected = $this->inspector->inspectFile(
                $absolute,
                $allowSvg || str_ends_with(strtolower($newRelative), '.svg'),
            );
        } catch (ImageRejectedException) {
            return;
        }

        $mtime = filemtime($absolute);
        $bytes = filesize($absolute);
        if (false === $mtime || false === $bytes) {
            return;
        }

        $this->persistFile(
            $absolute,
            $newRelative,
            MediaIndexPath::filename($newRelative),
            $inspected->mime,
            $inspected->width,
            $inspected->height,
            $bytes,
            $mtime,
            $existing,
            new MediaIndexRunSummary(),
        );
        $this->entityManager->flush();
    }

    public function registerFolder(string $relativePath, string $name): void
    {
        $relativePath = $this->paths->normalize($relativePath);
        if (MediaIndexPath::isHiddenRelativePath($relativePath)) {
            return;
        }

        $this->dryRun      = false;
        $this->folderCache = [];
        $this->ensureFolder($relativePath, $name);
        $this->entityManager->flush();
    }

    public function run(bool $reindexAll, bool $dryRun): MediaIndexRunSummary
    {
        $this->dryRun          = $dryRun;
        $this->seenFilePaths   = [];
        $this->seenFolderPaths = [];
        $this->folderCache     = [];
        $this->seenChecksums   = [];
        $summary               = new MediaIndexRunSummary();

        $root = $this->paths->root();
        if (!is_dir($root)) {
            return $summary;
        }

        $summary = $this->walkDirectory($root, '', $reindexAll, $summary);
        $summary = $this->reconcileMissingFromDisk($summary);
        $this->flushIfNeeded(true);

        return $summary;
    }

    private function walkDirectory(string $absolute, string $relative, bool $reindexAll, MediaIndexRunSummary $summary): MediaIndexRunSummary
    {
        if (is_link($absolute)) {
            return $summary;
        }

        $this->seenFolderPaths[$relative] = true;
        if ('' !== $relative) {
            $summary = $this->ensureFolderOnDisk($relative, MediaIndexPath::folderNameFromRelative($relative), $summary);
        }

        $entries = scandir($absolute);
        if (false === $entries) {
            return $summary;
        }

        foreach ($entries as $entry) {
            if (MediaIndexPath::isHiddenEntry($entry)) {
                continue;
            }

            $childAbsolute = $absolute.'/'.$entry;
            if (is_link($childAbsolute)) {
                continue;
            }

            $childRelative = '' === $relative ? $entry : $relative.'/'.$entry;

            if (is_dir($childAbsolute)) {
                $summary = $this->walkDirectory($childAbsolute, $childRelative, $reindexAll, $summary);
                continue;
            }

            if (!is_file($childAbsolute)) {
                $summary = $summary->withSkipped();
                continue;
            }

            $this->seenFilePaths[$childRelative] = true;
            $summary                             = $this->indexDiskFile($childAbsolute, $childRelative, $reindexAll, $summary);
        }

        return $summary;
    }

    private function indexDiskFile(string $absolute, string $relative, bool $reindexAll, MediaIndexRunSummary $summary): MediaIndexRunSummary
    {
        try {
            $inspected = $this->inspector->inspectFile($absolute, true);
        } catch (ImageRejectedException) {
            return $summary->withSkipped();
        }

        $mtime = filemtime($absolute);
        $bytes = filesize($absolute);
        if (false === $mtime || false === $bytes) {
            return $summary->withSkipped();
        }

        $existing          = $this->files->findOneByRelativePath($relative);
        $needsHashBackfill = !$existing instanceof MediaAssetFile
            || null === $existing->getChecksum()
            || '' === $existing->getChecksum();
        if (!$reindexAll && !$needsHashBackfill && $existing->isActive()
            && $existing->getFileMtime() === $mtime && $existing->getBytes() === $bytes) {
            return $summary;
        }

        return $this->persistFile(
            $absolute,
            $relative,
            MediaIndexPath::filename($relative),
            $inspected->mime,
            $inspected->width,
            $inspected->height,
            $bytes,
            $mtime,
            $existing,
            $summary,
        );
    }

    private function indexFileAtPath(string $absolute, string $relative, bool $allowSvg): void
    {
        $summary = new MediaIndexRunSummary();
        try {
            $inspected = $this->inspector->inspectFile($absolute, $allowSvg);
        } catch (ImageRejectedException) {
            return;
        }

        $mtime = filemtime($absolute);
        $bytes = filesize($absolute);
        if (false === $mtime || false === $bytes) {
            return;
        }

        $existing = $this->files->findOneByRelativePath($relative);

        $this->persistFile(
            $absolute,
            $relative,
            MediaIndexPath::filename($relative),
            $inspected->mime,
            $inspected->width,
            $inspected->height,
            $bytes,
            $mtime,
            $existing,
            $summary,
        );
    }

    private function persistFile(
        string $absolute,
        string $relative,
        string $filename,
        string $mime,
        int $width,
        int $height,
        int $bytes,
        int $mtime,
        ?MediaAssetFile $existing,
        MediaIndexRunSummary $summary,
    ): MediaIndexRunSummary {
        if (MediaIndexPath::isHiddenRelativePath($relative)) {
            return $summary->withSkipped();
        }

        try {
            $checksum = FileChecksum::hashFile($absolute);
        } catch (ImageRejectedException) {
            return $summary->withSkipped();
        }

        $parentRelative = MediaIndexPath::parentRelative($relative);
        if ('' !== $parentRelative) {
            $summary = $this->ensureFolderOnDisk(
                $parentRelative,
                MediaIndexPath::folderNameFromRelative($parentRelative),
                $summary,
            );
        }

        $folder = '' === $parentRelative ? null : $this->getFolder($parentRelative);

        $created = !$existing instanceof MediaAssetFile;
        $file    = $existing ?? new MediaAssetFile();
        $file->setRelativePath($relative)
            ->setFilename($filename)
            ->setMime($mime)
            ->setWidth($width)
            ->setHeight($height)
            ->setBytes($bytes)
            ->setFileMtime($mtime)
            ->setChecksum($checksum)
            ->setFolder($folder)
            ->setMissingSince(null);

        if ($created || null === $file->getDateAdded()) {
            $file->setDateAdded(new \DateTimeImmutable());
        }

        $duplicateChecksum = $created && (
            isset($this->seenChecksums[$checksum])
            || $this->files->hasOtherActiveWithChecksum($checksum, $relative)
        );
        $this->seenChecksums[$checksum] = true;

        if (!$this->dryRun) {
            $this->entityManager->persist($file);
        }

        if ($created) {
            $summary = $summary->withFilesIndexed();
            if ($duplicateChecksum) {
                $summary = $summary->withFilesIndexedDuplicateChecksum();
            }

            return $summary;
        }

        return $summary->withFilesUpdated();
    }

    private function ensureFolderOnDisk(string $relativePath, string $name, MediaIndexRunSummary $summary): MediaIndexRunSummary
    {
        if (MediaIndexPath::isHiddenRelativePath($relativePath)) {
            return $summary;
        }

        $existing = $this->getFolder($relativePath);
        if ($existing instanceof MediaAssetFolder) {
            return $this->refreshExistingFolder($existing, $relativePath, $summary);
        }

        return $this->ensureFolder($relativePath, $name, $summary);
    }

    private function ensureFolder(string $relativePath, string $name, MediaIndexRunSummary $summary = new MediaIndexRunSummary()): MediaIndexRunSummary
    {
        $relativePath = $this->paths->normalize($relativePath);
        if ('' === $relativePath || MediaIndexPath::isHiddenRelativePath($relativePath)) {
            return $summary;
        }

        $existing = $this->getFolder($relativePath);
        if ($existing instanceof MediaAssetFolder) {
            return $this->refreshExistingFolder($existing, $relativePath, $summary);
        }

        $parentRelative = MediaIndexPath::parentRelative($relativePath);
        $parent         = null;
        if ('' !== $parentRelative) {
            $summary = $this->ensureFolder(
                $parentRelative,
                MediaIndexPath::folderNameFromRelative($parentRelative),
                $summary,
            );
            $parent = $this->getFolder($parentRelative);
        }

        $folder = new MediaAssetFolder();
        $folder->setRelativePath($relativePath)
            ->setName($name)
            ->setParent($parent)
            ->setMissingSince(null);

        if (!$this->dryRun) {
            $this->entityManager->persist($folder);
        }

        $this->folderCache[$relativePath] = $folder;

        return $summary->withFoldersIndexed();
    }

    private function refreshExistingFolder(MediaAssetFolder $existing, string $relativePath, MediaIndexRunSummary $summary): MediaIndexRunSummary
    {
        $restored = false;
        if (!$existing->isActive()) {
            if (!$this->dryRun) {
                $existing->setMissingSince(null);
            }
            $restored = true;
        }

        $parentRelative = MediaIndexPath::parentRelative($relativePath);
        if ('' !== $parentRelative) {
            $summary = $this->ensureFolder(
                $parentRelative,
                MediaIndexPath::folderNameFromRelative($parentRelative),
                $summary,
            );
        }
        $expectedParent = '' === $parentRelative ? null : $this->getFolder($parentRelative);
        $currentParent  = $existing->getParent();
        $parentMismatch = ($expectedParent?->getRelativePath() ?? '') !== ($currentParent?->getRelativePath() ?? '');

        if (!$this->dryRun && ($restored || $parentMismatch)) {
            $existing->setParent($expectedParent);
            $this->entityManager->persist($existing);
        }

        if ($restored) {
            return $summary->withFoldersRestored();
        }

        return $summary;
    }

    private function getFolder(string $relativePath): ?MediaAssetFolder
    {
        if (isset($this->folderCache[$relativePath])) {
            return $this->folderCache[$relativePath];
        }

        $folder = $this->folders->findOneByRelativePath($relativePath);
        if ($folder instanceof MediaAssetFolder) {
            $this->folderCache[$relativePath] = $folder;
        }

        return $folder;
    }

    private function reconcileMissingFromDisk(MediaIndexRunSummary $summary): MediaIndexRunSummary
    {
        foreach ($this->files->findAllIndexed() as $file) {
            if (isset($this->seenFilePaths[$file->getRelativePath()])) {
                continue;
            }

            if (!$file->isActive()) {
                continue;
            }

            if (!$this->dryRun) {
                $file->setMissingSince(new \DateTimeImmutable());
                $this->entityManager->persist($file);
            }

            $summary = $summary->withFilesSoftDeleted();
        }

        foreach ($this->folders->findActiveFolders() as $folder) {
            if ('' === $folder->getRelativePath()) {
                continue;
            }

            if (isset($this->seenFolderPaths[$folder->getRelativePath()])) {
                continue;
            }

            if (!$this->dryRun) {
                $folder->setMissingSince(new \DateTimeImmutable());
                $this->entityManager->persist($folder);
            }

            $summary = $summary->withFoldersSoftDeleted();
        }

        return $summary;
    }

    private function flushIfNeeded(bool $force = false): void
    {
        if ($this->dryRun) {
            return;
        }

        if ($force) {
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->flush();
    }
}
