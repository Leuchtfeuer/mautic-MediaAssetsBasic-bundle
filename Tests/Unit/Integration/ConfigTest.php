<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Integration;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\IntegrationRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testIsPublishedWhenTheIntegrationIsPublished(): void
    {
        $integration = new Integration();
        $integration->setIsPublished(true);

        $this->assertTrue($this->config($integration)->isPublished());
    }

    public function testIsNotPublishedWhenTheIntegrationIsDisabled(): void
    {
        $integration = new Integration();
        $integration->setIsPublished(false);

        $this->assertFalse($this->config($integration)->isPublished());
    }

    public function testIsNotPublishedWhenTheIntegrationIsMissing(): void
    {
        $this->assertFalse($this->config(null)->isPublished());
    }

    private function config(?Integration $integration): Config
    {
        $repository = $this->createMock(IntegrationRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => LeuchtfeuerMediaAssetsBasicIntegration::NAME])
            ->willReturn($integration);

        return new Config($repository);
    }
}
