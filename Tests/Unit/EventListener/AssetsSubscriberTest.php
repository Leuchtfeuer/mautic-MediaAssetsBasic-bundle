<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use Mautic\InstallBundle\Install\InstallService;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\IntegrationRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\EventListener\AssetsSubscriber;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetsSubscriberTest extends TestCase
{
    public function testInjectsThePickerOnAnInstalledPublishedBuilderPage(): void
    {
        $event = new RecordingCustomAssetsEvent();
        $this->subscriber(true, true, Request::create('/s/pages/new'))->injectAssets($event);

        $this->assertSame(['plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/dist/picker.js'], $event->scripts);
        $this->assertSame(['plugins/LeuchtfeuerMediaAssetsBasicBundle/Assets/dist/picker.css'], $event->stylesheets);
        $this->assertCount(1, $event->declarations);
        $this->assertStringContainsString('window.LeuchtfeuerMediaAssetsBasic', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_image_list', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_image_upload', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_image_upload_check', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_image_import', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_folder_list', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_folder_create', $event->declarations[0]);
        $this->assertStringContainsString('leuchtfeuer_media_assets_basic_image_move', $event->declarations[0]);
        $this->assertStringContainsString('t:'.Translation::IMPORT_FROM_URL, $event->declarations[0]);
        $this->assertStringContainsString('"allowSvg":true', $event->declarations[0]);
    }

    public function testDisablesSvgOnEmailBuilderPages(): void
    {
        $event = new RecordingCustomAssetsEvent();
        $this->subscriber(true, true, Request::create('/s/emails/new'))->injectAssets($event);

        $this->assertCount(1, $event->declarations);
        $this->assertStringContainsString('"allowSvg":false', $event->declarations[0]);
    }

    public function testSkipsInjectionWhenThePluginIsUnpublished(): void
    {
        $event = new RecordingCustomAssetsEvent();
        $this->subscriber(true, false, Request::create('/s/emails/new'))->injectAssets($event);

        $this->assertSame([], $event->scripts);
    }

    public function testSkipsInjectionOutsideTheAdminPath(): void
    {
        $event = new RecordingCustomAssetsEvent();
        $this->subscriber(true, true, Request::create('/landing/hero'))->injectAssets($event);

        $this->assertSame([], $event->scripts);
    }

    public function testSkipsInjectionBeforeInstall(): void
    {
        $event = new RecordingCustomAssetsEvent();
        $this->subscriber(false, true, Request::create('/s/pages/new'))->injectAssets($event);

        $this->assertSame([], $event->scripts);
    }

    private function subscriber(bool $installed, bool $published, ?Request $request): AssetsSubscriber
    {
        $integration = new Integration();
        $integration->setIsPublished($published);
        $repository = $this->createMock(IntegrationRepository::class);
        $repository->method('findOneBy')
            ->with(['name' => LeuchtfeuerMediaAssetsBasicIntegration::NAME])
            ->willReturn($integration);
        $config = new Config($repository);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 't:'.$id);

        $installer = $this->createMock(InstallService::class);
        $installer->method('checkIfInstalled')->willReturn($installed);

        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => '/generated/'.$name);

        return new AssetsSubscriber($config, $translator, $installer, $stack, $router);
    }
}

final class RecordingCustomAssetsEvent extends \Mautic\CoreBundle\Event\CustomAssetsEvent
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<string> */
    public array $stylesheets = [];

    /** @var list<string> */
    public array $declarations = [];

    public function __construct()
    {
    }

    /**
     * @phpstan-ignore missingType.parameter, missingType.parameter
     */
    public function addScript($script, $location = 'head', $async = false, $name = null, $context = AssetsHelper::CONTEXT_APP): static
    {
        if (is_string($script)) {
            $this->scripts[] = $script;
        }

        return $this;
    }

    /**
     * @phpstan-ignore missingType.parameter
     */
    public function addStylesheet($stylesheet, $context = AssetsHelper::CONTEXT_APP): static
    {
        if (is_string($stylesheet)) {
            $this->stylesheets[] = $stylesheet;
        }

        return $this;
    }

    /**
     * @phpstan-ignore missingType.parameter
     */
    public function addScriptDeclaration($script, $location = 'head', $context = AssetsHelper::CONTEXT_APP): static
    {
        if (is_string($script)) {
            $this->declarations[] = $script;
        }

        return $this;
    }
}
