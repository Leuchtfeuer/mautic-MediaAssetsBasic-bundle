<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use PHPUnit\Framework\TestCase;

final class ImageUrlsTest extends TestCase
{
    public function testPrefersTheStaticUrlAndEncodesTheFileName(): void
    {
        $urls = $this->urls('https://cdn.example', 'https://site.example');

        $this->assertSame(
            'https://cdn.example/media/images/hero%20image.png',
            $urls->publicUrl('hero image.png'),
        );
    }

    public function testFallsBackToTheSiteUrl(): void
    {
        $urls = $this->urls('', 'https://site.example/');

        $this->assertSame('https://site.example/media/images/hero.png', $urls->publicUrl('hero.png'));
    }

    private function urls(string $staticUrl, string $siteUrl): ImageUrls
    {
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getImagePath')->willReturn('/var/www/media/images/');
        $paths->method('getSystemPath')->with('images', false)->willReturn('media/images');

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturnMap([
            ['static_url', $staticUrl],
            ['site_url', $siteUrl],
        ]);

        $urls = new ImageUrls($paths, $parameters);
        $this->assertSame('/var/www/media/images', $urls->directory());

        return $urls;
    }
}
