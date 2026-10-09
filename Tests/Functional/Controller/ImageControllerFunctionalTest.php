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
    /** @var list<string> */
    private array $uploadedPaths = [];

    /** @var list<string> */
    private array $createdFolders = [];

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
        $paths = self::getContainer()->get(PathsHelper::class);
        $this->assertInstanceOf(PathsHelper::class, $paths);
        $directory = rtrim($paths->getImagePath(), '/');
        foreach ($this->uploadedPaths as $relative) {
            $path = $directory.'/'.$relative;
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (array_reverse($this->createdFolders) as $relative) {
            $path = $directory.'/'.$relative;
            if (is_dir($path)) {
                rmdir($path);
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
        $this->uploadedPaths[] = $name;

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?limit=50');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $images = $list['images'] ?? null;
        $this->assertIsArray($images);
        $names = array_column($images, 'name');
        $this->assertContains($name, $names);
    }

    public function testFolderCreateUploadAndListStayInsideTheSelectedPath(): void
    {
        $this->publishPlugin();

        $folderName = 'Brand'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($created);
        $folder = $created['folder'] ?? null;
        $this->assertIsArray($folder);
        $this->assertSame($folderName, $folder['path'] ?? null);
        $this->createdFolders[] = $folderName;

        $name = $this->uploadPng('nested.png', $folderName);
        $this->assertSame('nested.png', $name);

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $images = $list['images'] ?? null;
        $this->assertIsArray($images);
        $this->assertSame([$folderName.'/nested.png'], array_column($images, 'path'));

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/folders');
        $tree = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($tree);
        $root = $tree['folder'] ?? null;
        $this->assertIsArray($root);
        $children = $root['children'] ?? null;
        $this->assertIsArray($children);
        $paths = array_column($children, 'path');
        $this->assertContains($folderName, $paths);
    }

    public function testListRejectsPathTraversal(): void
    {
        $this->publishPlugin();

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?path='.rawurlencode('../'));
        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'That folder path is not allowed.');
    }

    public function testHiddenDirectoriesAreOmittedAndRejected(): void
    {
        $this->publishPlugin();

        $paths = self::getContainer()->get(PathsHelper::class);
        $this->assertInstanceOf(PathsHelper::class, $paths);
        $directory = rtrim($paths->getImagePath(), '/');
        $hidden    = $directory.'/.hidden';
        mkdir($hidden, 0775, true);
        $this->createdFolders[] = '.hidden';
        file_put_contents($hidden.'/secret.png', $this->png());
        $this->uploadedPaths[] = '.hidden/secret.png';

        $visible = 'Visible'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $visible], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $visible;

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/folders');
        $tree = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($tree);
        $root = $tree['folder'] ?? null;
        $this->assertIsArray($root);
        $children = $root['children'] ?? null;
        $this->assertIsArray($children);
        $childPaths = array_column($children, 'path');
        $this->assertContains($visible, $childPaths);
        $this->assertNotContains('.hidden', $childPaths);

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?path='.rawurlencode('.hidden'));
        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'That folder path is not allowed.');

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => '.hidden-again'], JSON_THROW_ON_ERROR),
        );
        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'Enter a valid folder name.');
    }

    public function testUnpublishedPluginRejectsTheImageEndpoints(): void
    {
        $this->setPublished(false);

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images');
        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_FORBIDDEN, 'Media & Assets (Basic) is not published.');

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/import',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['url' => 'https://example.com/hero.png'], JSON_THROW_ON_ERROR),
        );
        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_FORBIDDEN, 'Media & Assets (Basic) is not published.');
    }

    public function testUploadRejectsAFileThatIsNotAnImage(): void
    {
        $this->publishPlugin();

        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, 'not an image');
        $file = new UploadedFile($path, 'notes.txt', 'text/plain', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            [],
            ['files' => [$file]],
            $this->ajaxHeaders(),
        );

        $upload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($upload);
        $files = $upload['files'] ?? null;
        $this->assertIsArray($files);
        $first = $files[0] ?? null;
        $this->assertIsArray($first);
        $this->assertFalse($first['ok'] ?? null);
        $this->assertSame('Only JPEG, PNG, GIF and WebP images are allowed.', $first['error'] ?? null);
    }

    public function testASecondUploadOfTheSameNameGetsASuffix(): void
    {
        $this->publishPlugin();

        $stem   = 'lfmedia'.bin2hex(random_bytes(4));
        $first  = $this->uploadPng($stem.'.png');
        $second = $this->uploadPng($stem.'.png');

        $this->assertSame($stem.'.png', $first);
        $this->assertSame($stem.'-1.png', $second);
    }

    public function testImportRequiresAUrl(): void
    {
        $this->publishPlugin();

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/import',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['url' => ''], JSON_THROW_ON_ERROR),
        );

        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'Enter an image URL.');
    }

    /**
     * @return array<string, string>
     */
    private function ajaxHeaders(): array
    {
        return [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token'     => $this->ajaxCsrfToken(),
        ];
    }

    private function assertRejected(string $content, int $status, string $message): void
    {
        $payload = json_decode($content, true);
        $this->assertResponseStatusCodeSame($status);
        $this->assertIsArray($payload);
        $this->assertSame($message, $payload['error'] ?? null);
    }

    private function uploadPng(string $filename, string $folder = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->png());
        $file = new UploadedFile($path, $filename, 'image/png', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            ['path' => $folder],
            ['files' => [$file]],
            $this->ajaxHeaders(),
        );

        $upload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($upload);
        $files = $upload['files'] ?? null;
        $this->assertIsArray($files);
        $first = $files[0] ?? null;
        $this->assertIsArray($first);
        $this->assertTrue($first['ok'] ?? null, (string) json_encode($first));
        $image = $first['image'] ?? null;
        $this->assertIsArray($image);
        $name     = $image['name'] ?? null;
        $relative = $image['path'] ?? $name;
        $this->assertIsString($name);
        $this->assertIsString($relative);
        $this->uploadedPaths[] = $relative;

        return $name;
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
        $this->setPublished(true);
    }

    private function setPublished(bool $published): void
    {
        $existing = $this->em->getRepository(Integration::class)->findOneBy([
            'name' => LeuchtfeuerMediaAssetsBasicIntegration::NAME,
        ]);
        if ($existing instanceof Integration) {
            $existing->setIsPublished($published);
            $this->em->flush();

            return;
        }

        if (!$published) {
            return;
        }

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
