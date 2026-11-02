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
        public int $renditionsGenerated = 0,
        public int $renditionsSkipped = 0,
        public int $renditionsFailed = 0,
        public float $elapsedSeconds = 0.0,
    ) {
    }

    public function withFilesIndexed(int $count = 1): self
    {
        return $this->with(filesIndexed: $this->filesIndexed + $count);
    }

    public function withFilesIndexedDuplicateChecksum(int $count = 1): self
    {
        return $this->with(filesIndexedDuplicateChecksum: $this->filesIndexedDuplicateChecksum + $count);
    }

    public function withFilesUpdated(int $count = 1): self
    {
        return $this->with(filesUpdated: $this->filesUpdated + $count);
    }

    public function withFilesSoftDeleted(int $count = 1): self
    {
        return $this->with(filesSoftDeleted: $this->filesSoftDeleted + $count);
    }

    public function withFilesRestored(int $count = 1): self
    {
        return $this->with(filesRestored: $this->filesRestored + $count);
    }

    public function withFoldersIndexed(int $count = 1): self
    {
        return $this->with(foldersIndexed: $this->foldersIndexed + $count);
    }

    public function withFoldersSoftDeleted(int $count = 1): self
    {
        return $this->with(foldersSoftDeleted: $this->foldersSoftDeleted + $count);
    }

    public function withFoldersRestored(int $count = 1): self
    {
        return $this->with(foldersRestored: $this->foldersRestored + $count);
    }

    public function withSkipped(int $count = 1): self
    {
        return $this->with(skipped: $this->skipped + $count);
    }

    public function withRenditionResult(RenditionEnsureResult $result): self
    {
        return $this->with(
            renditionsGenerated: $this->renditionsGenerated + $result->generated,
            renditionsSkipped: $this->renditionsSkipped + $result->skipped,
            renditionsFailed: $this->renditionsFailed + $result->failed,
        );
    }

    public function withElapsedSeconds(float $seconds): self
    {
        return $this->with(elapsedSeconds: $seconds);
    }

    private function with(
        ?int $filesIndexed = null,
        ?int $filesIndexedDuplicateChecksum = null,
        ?int $filesUpdated = null,
        ?int $filesSoftDeleted = null,
        ?int $filesRestored = null,
        ?int $foldersIndexed = null,
        ?int $foldersSoftDeleted = null,
        ?int $foldersRestored = null,
        ?int $skipped = null,
        ?int $renditionsGenerated = null,
        ?int $renditionsSkipped = null,
        ?int $renditionsFailed = null,
        ?float $elapsedSeconds = null,
    ): self {
        return new self(
            $filesIndexed ?? $this->filesIndexed,
            $filesIndexedDuplicateChecksum ?? $this->filesIndexedDuplicateChecksum,
            $filesUpdated ?? $this->filesUpdated,
            $filesSoftDeleted ?? $this->filesSoftDeleted,
            $filesRestored ?? $this->filesRestored,
            $foldersIndexed ?? $this->foldersIndexed,
            $foldersSoftDeleted ?? $this->foldersSoftDeleted,
            $foldersRestored ?? $this->foldersRestored,
            $skipped ?? $this->skipped,
            $renditionsGenerated ?? $this->renditionsGenerated,
            $renditionsSkipped ?? $this->renditionsSkipped,
            $renditionsFailed ?? $this->renditionsFailed,
            $elapsedSeconds ?? $this->elapsedSeconds,
        );
    }
}
