<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract;

interface HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
