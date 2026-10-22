<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaIndexPath;
use PHPUnit\Framework\TestCase;

final class MediaIndexPathTest extends TestCase
{
    public function testParentRelativeSplitsNestedPaths(): void
    {
        $this->assertSame('', MediaIndexPath::parentRelative('hero.png'));
        $this->assertSame('test', MediaIndexPath::parentRelative('test/ttt'));
        $this->assertSame('test/ttt', MediaIndexPath::parentRelative('test/ttt/nested.png'));
    }

    public function testFilenameReturnsTheLastSegment(): void
    {
        $this->assertSame('hero.png', MediaIndexPath::filename('hero.png'));
        $this->assertSame('ttt', MediaIndexPath::folderNameFromRelative('test/ttt'));
    }

    public function testDetectsHiddenEntriesAndRelativePaths(): void
    {
        $this->assertTrue(MediaIndexPath::isHiddenEntry('.hidden'));
        $this->assertFalse(MediaIndexPath::isHiddenEntry('Brand'));
        $this->assertTrue(MediaIndexPath::isHiddenRelativePath('Brand/.cache'));
        $this->assertFalse(MediaIndexPath::isHiddenRelativePath('Brand/Campaign'));
        $this->assertFalse(MediaIndexPath::isHiddenRelativePath(''));
    }
}
