<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;

class ConfigSupport extends LeuchtfeuerMediaAssetsBasicIntegration implements ConfigFormInterface
{
    use DefaultConfigFormTrait;
}
