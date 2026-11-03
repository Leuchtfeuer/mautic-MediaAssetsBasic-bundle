<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Enum;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use PHPUnit\Framework\TestCase;

final class RenditionPresetTest extends TestCase
{
    public function testLongEdgeAndQualityComeFromNamedConstants(): void
    {
        $this->assertSame(RenditionPreset::STANDARD_LONG_EDGE, RenditionPreset::Standard->longEdge());
        $this->assertSame(RenditionPreset::THUMB_LONG_EDGE, RenditionPreset::Thumb->longEdge());
        $this->assertSame(RenditionPreset::STANDARD_QUALITY, RenditionPreset::Standard->quality());
        $this->assertSame(RenditionPreset::THUMB_QUALITY, RenditionPreset::Thumb->quality());
    }
}
