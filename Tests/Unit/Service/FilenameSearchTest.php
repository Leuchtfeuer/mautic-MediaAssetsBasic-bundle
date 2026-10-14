<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FilenameSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenameSearchTest extends TestCase
{
    public function testIsActiveRequiresTwoCharacters(): void
    {
        $this->assertFalse(FilenameSearch::isActive(''));
        $this->assertFalse(FilenameSearch::isActive(' a '));
        $this->assertTrue(FilenameSearch::isActive('ab'));
        $this->assertTrue(FilenameSearch::isActive(' Bitmotion '));
    }

    public function testQueryTokensSplitOnWhitespace(): void
    {
        $this->assertSame(['hero', 'spring'], FilenameSearch::queryTokens('  hero   spring '));
        $this->assertSame([], FilenameSearch::queryTokens('   '));
    }

    public function testMultiWordQueryIsAndOrderIndependent(): void
    {
        $this->assertTrue(FilenameSearch::matches('hero-spring-2026.jpg', 'spring hero'));
        $this->assertTrue(FilenameSearch::matches('hero-spring-2026.jpg', 'hero spring'));
        $this->assertFalse(FilenameSearch::matches('hero-2026.jpg', 'spring hero'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->assertTrue(FilenameSearch::matches('BitMotion-Hero.JPG', 'bitmotion'));
        $this->assertSame(FilenameSearch::TIER_PREFIX, FilenameSearch::tier('BitMotion-Hero.JPG', 'bitmotion'));
    }

    #[DataProvider('tierProvider')]
    public function testTiers(string $filename, string $query, int $expectedTier): void
    {
        $this->assertSame($expectedTier, FilenameSearch::tier($filename, $query));
    }

    /**
     * @return \Generator<string, array{string, string, int}>
     */
    public static function tierProvider(): \Generator
    {
        yield 'exact' => ['Bitmotion.jpg', 'Bitmotion.jpg', FilenameSearch::TIER_EXACT];
        yield 'prefix' => ['Bitmotion-Grayscale.jpg', 'Bitmotion', FilenameSearch::TIER_PREFIX];
        yield 'token' => ['hero-spring-2026.jpg', 'spring', FilenameSearch::TIER_TOKEN];
        yield 'substring' => ['myspringboard.png', 'spring', FilenameSearch::TIER_SUBSTRING];
        yield 'none' => ['other.png', 'spring', FilenameSearch::TIER_NONE];
        yield 'multi-word token' => ['hero-spring-2026.jpg', 'hero spring', FilenameSearch::TIER_TOKEN];
        yield 'multi-word substring' => ['superspringhero.png', 'hero spring', FilenameSearch::TIER_SUBSTRING];
    }

    public function testParentFolder(): void
    {
        $this->assertSame('', FilenameSearch::parentFolder('root.jpg'));
        $this->assertSame('Campaign 2026', FilenameSearch::parentFolder('Campaign 2026/hero.jpg'));
        $this->assertSame('Campaign 2026/merch', FilenameSearch::parentFolder('Campaign 2026/merch/logo.png'));
    }

    public function testCompareOrdersByTierThenCurrentFolderThenMtimeThenId(): void
    {
        $query  = 'spring';
        $folder = 'Campaign';

        $rows = [
            // substring, current folder
            ['myspring.png', 'Campaign/myspring.png', 100, 1],
            // token, not current
            ['hero-spring.png', 'Campaign/merch/hero-spring.png', 500, 2],
            // prefix, current
            ['spring-hero.png', 'Campaign/spring-hero.png', 50, 3],
            // exact
            ['spring', 'Campaign/merch/spring', 10, 4],
            // prefix, not current, newer
            ['spring-old.png', 'Campaign/merch/spring-old.png', 400, 5],
            // prefix, not current, older, higher id wins when mtime equal — use same mtime
            ['spring-new.png', 'Campaign/merch/spring-new.png', 400, 6],
        ];

        usort(
            $rows,
            static fn (array $left, array $right): int => FilenameSearch::compare(
                $left[0],
                $left[1],
                $left[2],
                $left[3],
                $right[0],
                $right[1],
                $right[2],
                $right[3],
                $query,
                $folder,
            )
        );

        $this->assertSame(
            [
                'spring',
                'spring-hero.png',
                'spring-new.png',
                'spring-old.png',
                'hero-spring.png',
                'myspring.png',
            ],
            array_column($rows, 0)
        );
    }
}
