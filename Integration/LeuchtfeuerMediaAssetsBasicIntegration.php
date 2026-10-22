<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeuchtfeuerMediaAssetsBasicIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'LeuchtfeuerMediaAssetsBasic';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return $this->translator->trans(Translation::NAME);
    }

    public function getDescription(): string
    {
        return $this->translator->trans(Translation::DESCRIPTION);
    }

    public function getIcon(): string
    {
        return 'plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/img/icon.png';
    }
}
