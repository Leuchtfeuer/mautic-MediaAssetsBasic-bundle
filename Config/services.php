<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\HostResolver;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\MediaIndexer;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\DnsHostResolver;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaIndex;
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
            'Repository',
            'Translation.php',
        ])).'}');

    $services->load('MauticPlugin\\LeuchtfeuerMediaAssetsBasicBundle\\Repository\\', '../Repository')
        ->tag(Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    $services->alias(
        'mautic.integration.leuchtfeuermediaassetsbasic',
        LeuchtfeuerMediaAssetsBasicIntegration::class,
    );

    $services->alias(HostResolver::class, DnsHostResolver::class);
    $services->alias(MediaIndexer::class, MediaIndex::class);
};
