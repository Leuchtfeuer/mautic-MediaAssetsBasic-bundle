<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\HostResolver;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\DnsHostResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\LeuchtfeuerMediaAssetsBasicBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, [
            'vendor',
            'node_modules',
            'Assets',
            'Contract',
            'DTO',
            'Translation.php',
        ])).'}');

    $services->alias(
        'mautic.integration.leuchtfeuermediaassetsbasic',
        LeuchtfeuerMediaAssetsBasicIntegration::class,
    );

    $services->alias(HostResolver::class, DnsHostResolver::class);
};
