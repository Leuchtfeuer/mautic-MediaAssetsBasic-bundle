<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\HostResolver;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips     = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ([] === $ips) {
            $addresses = @gethostbynamel($host);
            if (is_array($addresses)) {
                $ips = $addresses;
            }
        }

        return array_values(array_unique($ips));
    }
}
