<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionPaths;
use PHPUnit\Framework\TestCase;

final class RenditionPathsTest extends TestCase
{
    public function testRelativeForMirrorsFolderStructureWithLongEdgeSuffix(): void
    {
        $this->assertSame(
            '_renditions/brand/campaign-2026/hero-spring_1600.jpg',
            RenditionPaths::relativePathFor('brand/campaign-2026/hero-spring.jpg', RenditionPreset::Standard),
        );
        $this->assertSame(
            '_renditions/brand/campaign-2026/hero-spring_400.jpg',
            RenditionPaths::relativePathFor('brand/campaign-2026/hero-spring.jpg', RenditionPreset::Thumb),
        );
        $this->assertSame(
            '_renditions/hero_400.png',
            RenditionPaths::relativePathFor('hero.png', RenditionPreset::Thumb),
        );
    }

    public function testDetectsRenditionsRootPaths(): void
    {
        $this->assertTrue(RenditionPaths::isRenditionsEntry('_renditions'));
        $this->assertFalse(RenditionPaths::isRenditionsEntry('brand'));
        $this->assertTrue(RenditionPaths::isRenditionsRelativePath('_renditions'));
        $this->assertTrue(RenditionPaths::isRenditionsRelativePath('_renditions/brand/hero_400.jpg'));
        $this->assertFalse(RenditionPaths::isRenditionsRelativePath('brand/hero.jpg'));
    }
}
