<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\IniSize;
use PHPUnit\Framework\TestCase;

final class IniSizeTest extends TestCase
{
    public function testConvertsIniShorthandToBytes(): void
    {
        $this->assertSame(2 * 1024 * 1024, IniSize::toBytes('2M'));
        $this->assertSame(512 * 1024, IniSize::toBytes('512K'));
        $this->assertSame(1024, IniSize::toBytes('1024'));
    }
}
