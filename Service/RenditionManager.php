<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\RenditionEnsureResult;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionState;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaRenditionRepository;

/**
 * Ensures standard/thumb rows + files for an indexed media asset.
 */
class RenditionManager
{
    public function __construct(
        private readonly ImageRenditionWriter $writer,
        private readonly RenditionPaths $renditionPaths,
        private readonly MediaPath $paths,
        private readonly MediaRenditionRepository $renditions,
        private readonly EntityManagerInterface $entityManager,
        private readonly RenditionLock $lock,
        private readonly ImageInspector $inspector,
    ) {
    }

    public function ensureForFile(MediaAssetFile $file, bool $dryRun = false, bool $failFast = false): RenditionEnsureResult
    {
        if (!$file->isActive()) {
            return new RenditionEnsureResult(skipped: 1);
        }

        if (ImageInspector::SVG_MIME === $file->getMime()) {
            return new RenditionEnsureResult(skipped: 1);
        }

        // Cheap path: skip lock/inspect when every preset is already satisfied on disk/DB.
        if (!$this->needsGeneration($file)) {
            return new RenditionEnsureResult(skipped: count(RenditionPreset::cases()));
        }

        return $this->lock->withLock(function (float $deadline) use ($file, $dryRun): RenditionEnsureResult {
            $this->lock->assertWithinDeadline($deadline);

            return $this->ensureLocked($file, $dryRun, $deadline);
        }, $failFast);
    }

    /**
     * Drop existing derived files/rows (e.g. after a move) and recreate from the master.
     */
    public function rebuildForFile(MediaAssetFile $file, bool $failFast = false): RenditionEnsureResult
    {
        return $this->lock->withLock(function (float $deadline) use ($file): RenditionEnsureResult {
            $this->lock->assertWithinDeadline($deadline);
            foreach ($this->renditions->findForFile($file) as $row) {
                $absolute = $this->paths->root().'/'.$row->getStoragePath();
                if ('' !== $row->getStoragePath() && is_file($absolute)) {
                    @unlink($absolute);
                }
                $this->entityManager->remove($row);
            }
            $this->entityManager->flush();

            return $this->ensureLocked($file, false, $deadline);
        }, $failFast);
    }

    /**
     * True when a lock + inspect pass is required (missing, failed, or ready file gone).
     */
    public function needsGeneration(MediaAssetFile $file): bool
    {
        if (!$file->isActive() || ImageInspector::SVG_MIME === $file->getMime()) {
            return false;
        }

        $byPreset = [];
        foreach ($this->renditions->findForFile($file) as $row) {
            $byPreset[$row->getPreset()->value] = $row;
        }

        foreach (RenditionPreset::cases() as $preset) {
            if (!$this->presetSatisfied($byPreset[$preset->value] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function presetSatisfied(?MediaRendition $row): bool
    {
        if (!$row instanceof MediaRendition) {
            return false;
        }

        if ($row->isNotNeeded()) {
            return true;
        }

        return $row->isReady() && '' !== $row->getStoragePath() && is_file($this->paths->root().'/'.$row->getStoragePath());
    }

    private function ensureLocked(MediaAssetFile $file, bool $dryRun, float $deadline): RenditionEnsureResult
    {
        $absolute = $this->paths->root().'/'.$file->getRelativePath();
        if (!is_file($absolute)) {
            return new RenditionEnsureResult(skipped: 1);
        }

        try {
            $mime        = $this->inspector->inspectFile($absolute, false)->mime;
            $animatedGif = $this->writer->isAnimatedGif($absolute, $mime);
        } catch (ImageRejectedException $exception) {
            return $this->markPresetsFailed($file, $exception->getMessage(), $dryRun);
        }

        $result = new RenditionEnsureResult();
        foreach (RenditionPreset::cases() as $preset) {
            $this->lock->assertWithinDeadline($deadline);
            $result = $result->merge($this->ensurePreset($file, $absolute, $preset, $animatedGif, $dryRun));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $result;
    }

    private function ensurePreset(
        MediaAssetFile $file,
        string $absolute,
        RenditionPreset $preset,
        bool $animatedGif,
        bool $dryRun,
    ): RenditionEnsureResult {
        $existing = $this->renditions->findOneForFilePreset($file, $preset);
        if ($this->presetSatisfied($existing)) {
            return new RenditionEnsureResult(skipped: 1);
        }

        if ($animatedGif && RenditionPreset::Standard === $preset) {
            if ($dryRun) {
                return new RenditionEnsureResult(skipped: 1);
            }

            return $this->persistNotNeeded($file, $preset, $existing);
        }

        // Prefer indexed dimensions over re-decoding when deciding standard is unnecessary.
        if (RenditionPreset::Standard === $preset
            && max($file->getWidth(), $file->getHeight()) <= $preset->longEdge()
        ) {
            if ($dryRun) {
                return new RenditionEnsureResult(skipped: 1);
            }

            return $this->persistNotNeeded($file, $preset, $existing);
        }

        if ($dryRun) {
            return new RenditionEnsureResult(generated: 1);
        }

        try {
            $generated = $this->writer->write($absolute, $file->getRelativePath(), $preset, $animatedGif);
        } catch (\Throwable $exception) {
            return $this->persistFailed($file, $preset, $existing, $exception->getMessage());
        }

        if (null === $generated) {
            return $this->persistNotNeeded($file, $preset, $existing);
        }

        $row = $existing ?? new MediaRendition();
        $now = new \DateTimeImmutable();
        if (!$existing instanceof MediaRendition || null === $row->getDateAdded()) {
            $row->setDateAdded($now);
        }

        $row->setFile($file)
            ->setPreset($preset)
            ->setFormat($generated->format)
            ->setWidth($generated->width)
            ->setHeight($generated->height)
            ->setBytes($generated->bytes)
            ->setStoragePath($generated->relativePath)
            ->setState(RenditionState::Ready)
            ->setFailureReason(null)
            ->setDateModified($now);

        $this->entityManager->persist($row);

        return new RenditionEnsureResult(generated: 1);
    }

    private function markPresetsFailed(MediaAssetFile $file, string $reason, bool $dryRun): RenditionEnsureResult
    {
        if ($dryRun) {
            return new RenditionEnsureResult(failed: count(RenditionPreset::cases()));
        }

        $result = new RenditionEnsureResult();
        foreach (RenditionPreset::cases() as $preset) {
            $existing = $this->renditions->findOneForFilePreset($file, $preset);
            $result   = $result->merge($this->persistFailed($file, $preset, $existing, $reason));
        }
        $this->entityManager->flush();

        return $result;
    }

    private function persistNotNeeded(
        MediaAssetFile $file,
        RenditionPreset $preset,
        ?MediaRendition $existing,
    ): RenditionEnsureResult {
        $row = $existing ?? new MediaRendition();
        $now = new \DateTimeImmutable();
        if (!$existing instanceof MediaRendition || null === $row->getDateAdded()) {
            $row->setDateAdded($now);
        }

        if ('' !== $row->getStoragePath()) {
            $absolute = $this->paths->root().'/'.$row->getStoragePath();
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $row->setFile($file)
            ->setPreset($preset)
            ->setFormat('')
            ->setWidth(0)
            ->setHeight(0)
            ->setBytes(0)
            ->setStoragePath('')
            ->setState(RenditionState::NotNeeded)
            ->setFailureReason(null)
            ->setDateModified($now);

        $this->entityManager->persist($row);

        return new RenditionEnsureResult(skipped: 1);
    }

    private function persistFailed(
        MediaAssetFile $file,
        RenditionPreset $preset,
        ?MediaRendition $existing,
        string $reason,
    ): RenditionEnsureResult {
        $row = $existing ?? new MediaRendition();
        $now = new \DateTimeImmutable();
        if (!$existing instanceof MediaRendition || null === $row->getDateAdded()) {
            $row->setDateAdded($now);
        }

        $row->setFile($file)
            ->setPreset($preset)
            ->setFormat('')
            ->setWidth(0)
            ->setHeight(0)
            ->setBytes(0)
            ->setStoragePath($this->renditionPaths->relativeFor($file->getRelativePath(), $preset))
            ->setState(RenditionState::Failed)
            ->setFailureReason($reason)
            ->setDateModified($now);

        $this->entityManager->persist($row);

        return new RenditionEnsureResult(failed: 1);
    }
}
