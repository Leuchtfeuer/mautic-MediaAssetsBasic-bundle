<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\LandingPagePickerContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LandingPagePickerContextTest extends TestCase
{
    public function testDetectsLandingPageBuilderRoutes(): void
    {
        $this->assertTrue(LandingPagePickerContext::isLandingPageBuilderRequest(Request::create('/s/pages/new')));
        $this->assertTrue(LandingPagePickerContext::isLandingPageBuilderRequest(Request::create('/s/pages/edit/42')));
        $this->assertTrue(LandingPagePickerContext::isLandingPageBuilderRequest(Request::create('/s/pages/clone/7')));
        $this->assertFalse(LandingPagePickerContext::isLandingPageBuilderRequest(Request::create('/s/emails/new')));
        $this->assertFalse(LandingPagePickerContext::isLandingPageBuilderRequest(Request::create('/s/pages/1')));
    }

    public function testAllowsSvgWhenTheRefererPointsToTheLandingPageBuilder(): void
    {
        $request = Request::create('/s/media-assets-basic/images/upload', Request::METHOD_POST);
        $request->headers->set('Referer', 'https://mautic.example/s/pages/edit/12');

        $this->assertTrue(LandingPagePickerContext::requestAllowsSvg($request));
    }

    public function testRejectsSvgWhenTheRefererPointsToAnotherBuilder(): void
    {
        $request = Request::create('/s/media-assets-basic/images/upload', Request::METHOD_POST);
        $request->headers->set('Referer', 'https://mautic.example/s/emails/new');

        $this->assertFalse(LandingPagePickerContext::requestAllowsSvg($request));
    }

    public function testRejectsSvgWithoutABuilderReferer(): void
    {
        $request = Request::create('/s/media-assets-basic/images/upload', Request::METHOD_POST);

        $this->assertFalse(LandingPagePickerContext::requestAllowsSvg($request));
    }
}
