<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum;

enum RenditionState: string
{
    case Ready     = 'ready';
    case Failed    = 'failed';
    case NotNeeded = 'not_needed';
}
