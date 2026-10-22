<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\HostResolver;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final readonly class PublicHostGuard
{
    public function __construct(private HostResolver $resolver)
    {
    }

    /**
     * @return list<string>
     */
    public function safeAddresses(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ImageRejectedException(Translation::ERROR_SCHEME);
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ImageRejectedException(Translation::ERROR_SCHEME);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImageRejectedException(Translation::ERROR_CREDENTIALS);
        }

        $host = trim($parts['host'], '[]');
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return [$host];
        }

        $ips = $this->resolver->resolve($host);
        if ([] === $ips) {
            throw new ImageRejectedException(Translation::ERROR_RESOLVE);
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }

        return $ips;
    }

    public function assertPublicIp(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new ImageRejectedException(Translation::ERROR_BLOCKED);
        }
    }
}
