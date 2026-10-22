<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum;

enum UploadDecision: string
{
    case UseExisting = 'use_existing';
    case KeepBoth    = 'keep_both';
    case Replace     = 'replace';
}
