<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\LeuchtfeuerMediaAssetsBasicIntegration;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class ImageControllerFunctionalTest extends MauticMysqlTestCase
{
    private string $uploadedName = '';

    private int $errorHandlerCountBeforeSetup = 0;

    private int $exceptionHandlerCountBeforeSetup = 0;

    protected function setUp(): void
    {
        $this->errorHandlerCountBeforeSetup     = $this->countErrorHandlers();
        $this->exceptionHandlerCountBeforeSetup = $this->countExceptionHandlers();

        parent::setUp();
    }

    protected function beforeTearDown(): void
    {
        if ('' !== $this->uploadedName) {
            $paths = self::getContainer()->get(PathsHelper::class);
            $this->assertInstanceOf(PathsHelper::class, $paths);
            $path = rtrim($paths->getImagePath(), '/').'/'.$this->uploadedName;
            if (is_file($path)) {
                unlink($path);
            }
        }

        while ($this->countErrorHandlers() > $this->errorHandlerCountBeforeSetup) {
            restore_error_handler();
        }

        // AbstractMauticTestCase::tearDown() restores one exception handler after this method.
        $exceptionHandlerTarget = $this->exceptionHandlerCountBeforeSetup + 1;
        while ($this->countExceptionHandlers() > $exceptionHandlerTarget) {
            restore_exception_handler();
        }
    }

    public function testUploadAppearsInTheImageList(): void
    {
        $this->publishPlugin();

        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->png());
        $file = new UploadedFile($path, 'hero.png', 'image/png', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            [],
            ['files' => [$file]],
            [
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
                'HTTP_X-CSRF-Token'     => $this->ajaxCsrfToken(),
            ],
        );

        $upload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($upload);
        $files = $upload['files'] ?? null;
        $this->assertIsArray($files);
        $first = $files[0] ?? null;
        $this->assertIsArray($first);
        $this->assertTrue($first['ok'] ?? null);
        $image = $first['image'] ?? null;
        $this->assertIsArray($image);
        $name = $image['name'] ?? null;
        $this->assertIsString($name);
        $this->uploadedName = $name;

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?limit=50');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $images = $list['images'] ?? null;
        $this->assertIsArray($images);
        $names = array_column($images, 'name');
        $this->assertContains($this->uploadedName, $names);
    }

    private function ajaxCsrfToken(): string
    {
        $session = $this->client->getSession();
        $this->assertInstanceOf(SessionInterface::class, $session);

        $request = new Request();
        $request->setSession($session);
        $stack = self::getContainer()->get('request_stack');
        $this->assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        try {
            $token = $this->getCsrfToken('mautic_ajax_post');
        } finally {
            $stack->pop();
        }
        $session->save();

        return $token;
    }

    private function publishPlugin(): void
    {
        $plugin = new Plugin();
        $plugin->setName('Media & Assets (Basic) by Leuchtfeuer');
        $plugin->setBundle('LeuchtfeuerMediaAssetsBasicBundle');
        $this->em->persist($plugin);

        $integration = new Integration();
        $integration->setPlugin($plugin);
        $integration->setName(LeuchtfeuerMediaAssetsBasicIntegration::NAME);
        $integration->setIsPublished(true);
        $this->em->persist($integration);
        $this->em->flush();
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png;
    }

    private function countErrorHandlers(): int
    {
        $handlers = [];

        while (true) {
            $previous = set_error_handler(static fn (): false => false);
            restore_error_handler();

            if (null === $previous) {
                break;
            }

            $handlers[] = $previous;
            restore_error_handler();
        }

        foreach (array_reverse($handlers) as $handler) {
            set_error_handler($handler);
        }

        return count($handlers);
    }

    private function countExceptionHandlers(): int
    {
        $handlers = [];

        while (true) {
            $previous = set_exception_handler(static fn (): null => null);
            restore_exception_handler();

            if (null === $previous) {
                break;
            }

            $handlers[] = $previous;
            restore_exception_handler();
        }

        foreach (array_reverse($handlers) as $handler) {
            set_exception_handler($handler);
        }

        return count($handlers);
    }
}
