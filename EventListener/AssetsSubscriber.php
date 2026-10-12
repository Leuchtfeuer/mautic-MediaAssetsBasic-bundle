<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\InstallBundle\Install\InstallService;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AssetsSubscriber implements EventSubscriberInterface
{
    private const SCRIPT_PATH = 'plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/dist/picker.js';

    private const STYLE_PATH = 'plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/dist/picker.css';

    public function __construct(
        private Config $config,
        private TranslatorInterface $translator,
        private InstallService $installer,
        private RequestStack $requestStack,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_ASSETS => ['injectAssets', 0],
        ];
    }

    public function injectAssets(CustomAssetsEvent $assetsEvent): void
    {
        // isPublished() reads the integration row. Skip that while Mautic itself is still installing and has no database yet.
        if (!$this->installer->checkIfInstalled() || !$this->config->isPublished()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !preg_match('/^\/s\//', $request->getPathInfo())) {
            return;
        }

        $messages = [];
        foreach (Translation::picker() as $name => $key) {
            $messages[$name] = $this->translator->trans($key);
        }

        $config = [
            'listUrl'          => $this->router->generate('leuchtfeuer_media_assets_basic_image_list'),
            'uploadUrl'        => $this->router->generate('leuchtfeuer_media_assets_basic_image_upload'),
            'importUrl'        => $this->router->generate('leuchtfeuer_media_assets_basic_image_import'),
            'foldersUrl'       => $this->router->generate('leuchtfeuer_media_assets_basic_folder_list'),
            'createFolderUrl'  => $this->router->generate('leuchtfeuer_media_assets_basic_folder_create'),
            'moveUrl'          => $this->router->generate('leuchtfeuer_media_assets_basic_image_move'),
            'messages'         => $messages,
        ];

        $assetsEvent->addScriptDeclaration(
            'window.LeuchtfeuerMediaAssetsBasic = '.json_encode($config, JSON_THROW_ON_ERROR).';'
        );
        $assetsEvent->addStylesheet(self::STYLE_PATH);
        $assetsEvent->addScript(self::SCRIPT_PATH);
    }
}
