<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Integration;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use PHPUnit\Framework\TestCase;

final class LeuchtfeuerMediaAssetsBasicIntegrationTest extends TestCase
{
    public function testExposesTheIntegrationName(): void
    {
        $integration = new LeuchtfeuerMediaAssetsBasicIntegration();

        $this->assertSame(LeuchtfeuerMediaAssetsBasicIntegration::NAME, $integration->getName());
        $this->assertSame('Media Assets Basic by Leuchtfeuer', $integration->getDisplayName());
        $this->assertSame(
            'plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/img/icon.png',
            $integration->getIcon(),
        );
    }
}
