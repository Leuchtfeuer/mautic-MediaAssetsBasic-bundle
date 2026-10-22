<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

final readonly class MediaIndexRunSummary
{
    public function __construct(
        public int $filesIndexed = 0,
        public int $filesIndexedDuplicateChecksum = 0,
        public int $filesUpdated = 0,
        public int $filesSoftDeleted = 0,
        public int $filesRestored = 0,
        public int $foldersIndexed = 0,
        public int $foldersSoftDeleted = 0,
        public int $foldersRestored = 0,
        public int $skipped = 0,
    ) {
    }

    public function withFilesIndexed(int $count = 1): self
    {
        return new self(
            $this->filesIndexed + $count,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFilesIndexedDuplicateChecksum(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum + $count,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFilesUpdated(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated + $count,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFilesSoftDeleted(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted + $count,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFilesRestored(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored + $count,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFoldersIndexed(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed + $count,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFoldersSoftDeleted(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted + $count,
            $this->foldersRestored,
            $this->skipped,
        );
    }

    public function withFoldersRestored(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored + $count,
            $this->skipped,
        );
    }

    public function withSkipped(int $count = 1): self
    {
        return new self(
            $this->filesIndexed,
            $this->filesIndexedDuplicateChecksum,
            $this->filesUpdated,
            $this->filesSoftDeleted,
            $this->filesRestored,
            $this->foldersIndexed,
            $this->foldersSoftDeleted,
            $this->foldersRestored,
            $this->skipped + $count,
        );
    }
}
