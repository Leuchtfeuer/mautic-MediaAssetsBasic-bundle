<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class LeuchtfeuerMediaAssetsBasicIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'LeuchtfeuerMediaAssetsBasic';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Media & Assets (Basic) by Leuchtfeuer';
    }

    public function getIcon(): string
    {
        return 'plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/img/icon.png';
    }
}
