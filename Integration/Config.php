<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration;

use Mautic\PluginBundle\Entity\IntegrationRepository;

final readonly class Config
{
    public function __construct(private IntegrationRepository $integrationRepository)
    {
    }

    public function isPublished(): bool
    {
        $integration = $this->integrationRepository->findOneBy([
            'name' => LeuchtfeuerMediaAssetsBasicIntegration::NAME,
        ]);

        return (bool) ($integration?->getIsPublished() ?? false);
    }
}
