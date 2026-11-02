<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\MediaIndexRunSummary;

interface MediaIndexer
{
    public function registerUploaded(ListedImage $image, bool $allowSvg, bool $generateRenditions = true): void;

    public function registerMoved(string $oldRelative, ListedImage $image, bool $allowSvg): void;

    public function registerFolder(string $relativePath, string $name): void;

    public function run(bool $reindexAll, bool $dryRun, bool $skipRenditions = false): MediaIndexRunSummary;
}
