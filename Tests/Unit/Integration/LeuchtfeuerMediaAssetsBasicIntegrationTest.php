<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Integration;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LeuchtfeuerMediaAssetsBasicIntegrationTest extends TestCase
{
    public function testExposesTheIntegrationName(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            Translation::NAME        => 'Media & Assets (Basic) by Leuchtfeuer',
            Translation::DESCRIPTION => 'Powerful Media & Assets Manager for organizing and picking images etc. in the Mautic builders',
            default                  => $key,
        });
        $integration = new LeuchtfeuerMediaAssetsBasicIntegration($translator);

        $this->assertSame(LeuchtfeuerMediaAssetsBasicIntegration::NAME, $integration->getName());
        $this->assertSame('Media & Assets (Basic) by Leuchtfeuer', $integration->getDisplayName());
        $this->assertSame(
            'Powerful Media & Assets Manager for organizing and picking images etc. in the Mautic builders',
            $integration->getDescription(),
        );
        $this->assertSame(
            'plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/img/icon.png',
            $integration->getIcon(),
        );
    }
}
