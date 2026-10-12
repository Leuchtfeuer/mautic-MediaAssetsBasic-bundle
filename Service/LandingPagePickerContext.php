<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Symfony\Component\HttpFoundation\Request;

final class LandingPagePickerContext
{
    public function allowSvg(Request $request): bool
    {
        return self::requestAllowsSvg($request);
    }

    public static function isLandingPageBuilderRequest(Request $request): bool
    {
        return self::isLandingPageBuilderPath($request->getPathInfo());
    }

    public static function requestAllowsSvg(Request $request): bool
    {
        if (self::isLandingPageBuilderRequest($request)) {
            return true;
        }

        foreach (self::sourceUrls($request) as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && self::isLandingPageBuilderPath($path)) {
                return true;
            }
        }

        return false;
    }

    public static function isLandingPageBuilderPath(string $path): bool
    {
        return 1 === preg_match('#^/s/pages/(?:new|edit/\d+|clone/\d+)(?:/|$)#', $path);
    }

    /**
     * @return list<string>
     */
    private static function sourceUrls(Request $request): array
    {
        $urls    = [];
        $referer = $request->headers->get('Referer');
        if (is_string($referer) && '' !== $referer) {
            $urls[] = $referer;
        }

        $origin = $request->headers->get('Origin');
        if (is_string($origin) && '' !== $origin) {
            $urls[] = $origin;
        }

        return $urls;
    }
}
