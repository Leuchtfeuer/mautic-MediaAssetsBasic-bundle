<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\EventListener;

use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Clears a stale plugins-page filter after reinstall (core warns on undefined $pluginName otherwise).
 */
final readonly class PluginLifecycleEventSubscriber implements EventSubscriberInterface
{
    private const BUNDLE = 'LeuchtfeuerMediaAssetsBasicBundle';

    private const SESSION_FILTER = 'mautic.integrations.filter';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if (self::BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->clearStalePluginFilter();
    }

    public function onUpdate(PluginUpdateEvent $event): void
    {
        if (self::BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->clearStalePluginFilter();
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => 'onInstall',
            PluginEvents::ON_PLUGIN_UPDATE  => 'onUpdate',
        ];
    }

    private function clearStalePluginFilter(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $request->getSession()->remove(self::SESSION_FILTER);
    }
}
