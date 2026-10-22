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

    public function testMoveImageIntoAnotherFolder(): void
    {
        $this->publishPlugin();

        $folderName = 'Archive'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $relative = $this->uploadPng('move-me.png');
        $this->assertSame('move-me.png', $relative);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/move',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['path' => 'move-me.png', 'folder' => $folderName], JSON_THROW_ON_ERROR),
        );
        $moved = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($moved);
        $image = $moved['image'] ?? null;
        $this->assertIsArray($image);
        $this->assertSame($folderName.'/move-me.png', $image['path'] ?? null);
        $this->uploadedPaths[] = $folderName.'/move-me.png';

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?limit=50');
        $rootList = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($rootList);
        $rootImages = $rootList['images'] ?? null;
        $this->assertIsArray($rootImages);
        $rootNames = array_column($rootImages, 'name');
        $this->assertNotContains('move-me.png', $rootNames);

        $this->client->request(Request::METHOD_GET, '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName));
        $folderList = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($folderList);
        $folderImages = $folderList['images'] ?? null;
        $this->assertIsArray($folderImages);
        $this->assertSame([$folderName.'/move-me.png'], array_column($folderImages, 'path'));
    }

    public function testMoveRequiresAnImagePath(): void
    {
        $this->publishPlugin();

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/move',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['path' => '', 'folder' => ''], JSON_THROW_ON_ERROR),
        );

        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'That folder path is not allowed.');
    }

    public function testMoveRejectsTheCurrentFolder(): void
    {
        $this->publishPlugin();

        $this->uploadPng('stay.png');

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/move',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['path' => 'stay.png', 'folder' => ''], JSON_THROW_ON_ERROR),
        );

        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'That folder path is not allowed.');
        $paths = self::getContainer()->get(PathsHelper::class);
        $this->assertInstanceOf(PathsHelper::class, $paths);
        $this->assertFileExists(rtrim($paths->getImagePath(), '/').'/stay.png');
    }

    public function testMoveAssignsASuffixWhenTheTargetFolderAlreadyHasTheFile(): void
    {
        $this->publishPlugin();

        $folderName = 'Dupes'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $this->uploadPng('dup.png', $folderName);
        $this->uploadPng('dup.png');

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/move',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['path' => 'dup.png', 'folder' => $folderName], JSON_THROW_ON_ERROR),
        );
        $moved = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($moved);
        $image = $moved['image'] ?? null;
        $this->assertIsArray($image);
        $this->assertSame($folderName.'/dup-1.png', $image['path'] ?? null);
        $this->uploadedPaths[] = $folderName.'/dup-1.png';
    }

    public function testMoveRejectsAnUnknownImagePath(): void
    {
        $this->publishPlugin();

        $folderName = 'Empty'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/move',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['path' => 'does-not-exist.png', 'folder' => $folderName], JSON_THROW_ON_ERROR),
        );

        $this->assertRejected((string) $this->client->getResponse()->getContent(), Response::HTTP_BAD_REQUEST, 'That folder path is not allowed.');
    }

    public function testSearchFindsFilesInFolderAndSubfoldersByName(): void
    {
        $this->publishPlugin();

        $folderName = 'Campaign'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $childName = 'merch';
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => $folderName, 'name' => $childName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName.'/'.$childName;

        $this->uploadPng('bitmotion-hero.png', $folderName);
        $this->uploadPng('other.png', $folderName);
        $this->uploadPng('bitmotion-merch.png', $folderName.'/'.$childName);
        $this->uploadPng('bitmotion-root.png');

        $this->client->request(
            Request::METHOD_GET,
            '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName).'&q=bitmotion',
        );
        $scoped = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($scoped);
        $scopedImages = $scoped['images'] ?? null;
        $this->assertIsArray($scopedImages);
        $scopedPaths = array_column($scopedImages, 'path');
        $this->assertContains($folderName.'/bitmotion-hero.png', $scopedPaths);
        $this->assertContains($folderName.'/'.$childName.'/bitmotion-merch.png', $scopedPaths);
        $this->assertNotContains($folderName.'/other.png', $scopedPaths);
        $this->assertNotContains('bitmotion-root.png', $scopedPaths);

        $this->client->request(
            Request::METHOD_GET,
            '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName).'&q=bitmotion&everywhere=1',
        );
        $everywhere = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($everywhere);
        $everywhereImages = $everywhere['images'] ?? null;
        $this->assertIsArray($everywhereImages);
        $everywherePaths = array_column($everywhereImages, 'path');
        $this->assertContains('bitmotion-root.png', $everywherePaths);
        $this->assertContains($folderName.'/bitmotion-hero.png', $everywherePaths);
    }

    public function testShortSearchQueryKeepsPlainFolderListing(): void
    {
        $this->publishPlugin();

        $folderName = 'ShortQ'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $this->uploadPng('alpha.png', $folderName);
        $this->uploadPng('beta.png', $folderName);

        $this->client->request(
            Request::METHOD_GET,
            '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName).'&q=a',
        );
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $images = $list['images'] ?? null;
        $this->assertIsArray($images);
        $names = array_column($images, 'name');
        sort($names);
        $this->assertSame(['alpha.png', 'beta.png'], $names);
        $this->assertSame(2, $list['total'] ?? null);
    }

    public function testSearchMultiWordAndRequiresAllTokens(): void
    {
        $this->publishPlugin();

        $folderName = 'AndQ'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $this->uploadPng('hero-spring-2026.png', $folderName);
        $this->uploadPng('hero-only.png', $folderName);
        $this->uploadPng('spring-only.png', $folderName);

        $this->client->request(
            Request::METHOD_GET,
            '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName).'&q='.rawurlencode('spring hero'),
        );
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $images = $list['images'] ?? null;
        $this->assertIsArray($images);
        $names = array_column($images, 'name');
        $this->assertSame(['hero-spring-2026.png'], $names);
        $this->assertSame(1, $list['total'] ?? null);
    }

    public function testSearchOrdersByTierAndPrefersCurrentFolder(): void
    {
        $this->publishPlugin();

        $folderName = 'Tier'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;

        $childName = 'nested';
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => $folderName, 'name' => $childName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName.'/'.$childName;

        // Query "spring": prefix (current) / prefix (nested) / token / substring
        $this->uploadPng('spring-banner.png', $folderName);
        $this->uploadPng('spring-nested.png', $folderName.'/'.$childName);
        $this->uploadPng('hero-spring.png', $folderName.'/'.$childName);
        $this->uploadPng('myspring.png', $folderName);

        $this->client->request(
            Request::METHOD_GET,
            '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName).'&q=spring',
        );
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $images = $list['images'] ?? null;
        $this->assertIsArray($images);
        $names = array_column($images, 'name');

        $this->assertSame('spring-banner.png', $names[0] ?? null, 'Current-folder prefix should beat nested prefix');
        $this->assertSame('spring-nested.png', $names[1] ?? null);
        $this->assertSame('hero-spring.png', $names[2] ?? null);
        $this->assertSame('myspring.png', $names[3] ?? null);
    }

    public function testSearchEmptyResultReturnsZeroImages(): void
    {
        $this->publishPlugin();

        $folderName = 'EmptyQ'.bin2hex(random_bytes(3));
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/folders',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent' => '', 'name' => $folderName], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->createdFolders[] = $folderName;
        $this->uploadPng('present.png', $folderName);

        $this->client->request(
            Request::METHOD_GET,
            '/s/media-assets-basic/images?limit=50&path='.rawurlencode($folderName).'&q=missingname',
        );
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($list);
        $this->assertSame([], $list['images'] ?? null);
        $this->assertSame(0, $list['total'] ?? null);
        $this->assertSame('missingname', $list['q'] ?? null);
        $this->assertFalse($list['everywhere'] ?? true);
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

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/move',
            [],
            [],
            $this->ajaxHeaders() + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['path' => 'hero.png', 'folder' => 'Archive'], JSON_THROW_ON_ERROR),
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

    public function testUploadAcceptsSvgInTheLandingPagePickerContext(): void
    {
        $this->publishPlugin();

        $name = $this->uploadSvg('lf-icon.svg', '', '/s/pages/new');
        $this->assertSame('lf-icon.svg', $name);

        $paths = self::getContainer()->get(PathsHelper::class);
        $this->assertInstanceOf(PathsHelper::class, $paths);
        $this->assertFileExists(rtrim($paths->getImagePath(), '/').'/lf-icon.svg');
    }

    public function testUploadRejectsSvgOutsideTheLandingPagePickerContext(): void
    {
        $this->publishPlugin();

        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->svg());
        $file = new UploadedFile($path, 'blocked.svg', 'image/svg+xml', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            [],
            ['files' => [$file]],
            $this->ajaxHeaders('/s/emails/new'),
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
        $second = $this->uploadPng($stem.'.png', '', 'keep_both');

        $this->assertSame($stem.'.png', $first);
        $this->assertSame($stem.'-1.png', $second);
    }

    public function testChecksumDuplicateCheckAndUseExisting(): void
    {
        $this->publishPlugin();

        $seed = 'same-'.bin2hex(random_bytes(3));
        $this->uploadPng('original.png', '', null, $seed);

        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->png($seed));
        $file = new UploadedFile($path, 'copy.png', 'image/png', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload/check',
            ['path' => ''],
            ['files' => [$file]],
            $this->ajaxHeaders(),
        );
        $check = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($check);
        $files = $check['files'] ?? null;
        $this->assertIsArray($files);
        $first = $files[0] ?? null;
        $this->assertIsArray($first);
        $this->assertTrue($first['ok'] ?? null);
        $conflict = $first['conflict'] ?? null;
        $this->assertIsArray($conflict);
        $this->assertSame('checksum_duplicate', $conflict['type'] ?? null);
        $existing = $conflict['existing'] ?? null;
        $this->assertIsArray($existing);
        $this->assertSame('original.png', $existing['path'] ?? null);

        $path2 = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path2);
        file_put_contents($path2, $this->png($seed));
        $file2 = new UploadedFile($path2, 'copy.png', 'image/png', null, true);
        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            ['path' => '', 'decision' => 'use_existing'],
            ['files' => [$file2]],
            $this->ajaxHeaders(),
        );
        $upload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertIsArray($upload);
        $uploadFiles = $upload['files'] ?? null;
        $this->assertIsArray($uploadFiles);
        $row = $uploadFiles[0] ?? null;
        $this->assertIsArray($row);
        $this->assertTrue($row['ok'] ?? null);
        $this->assertTrue($row['usedExisting'] ?? null);
        $image = $row['image'] ?? null;
        $this->assertIsArray($image);
        $this->assertSame('original.png', $image['path'] ?? null);
    }

    public function testNameClashReplaceOverwritesInPlace(): void
    {
        $this->publishPlugin();

        $name = 'replace-'.bin2hex(random_bytes(3)).'.png';
        $this->uploadPng($name, '', null, 'old-content');

        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->png('new-content'));
        $file = new UploadedFile($path, $name, 'image/png', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            ['path' => '', 'decision' => 'replace'],
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
        $this->assertSame($name, $image['path'] ?? null);

        $paths = self::getContainer()->get(PathsHelper::class);
        $this->assertInstanceOf(PathsHelper::class, $paths);
        $absolute = rtrim($paths->getImagePath(), '/').'/'.$name;
        $this->assertFileExists($absolute);
        $this->assertSame(hash('sha256', $this->png('new-content')), hash_file('sha256', $absolute));
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
    private function ajaxHeaders(?string $refererPath = null): array
    {
        $headers = [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token'     => $this->ajaxCsrfToken(),
        ];
        if (null !== $refererPath) {
            $headers['HTTP_Referer'] = 'https://mautic.test'.$refererPath;
        }

        return $headers;
    }

    private function assertRejected(string $content, int $status, string $message): void
    {
        $payload = json_decode($content, true);
        $this->assertResponseStatusCodeSame($status);
        $this->assertIsArray($payload);
        $this->assertSame($message, $payload['error'] ?? null);
    }

    private function uploadSvg(string $filename, string $folder = '', ?string $refererPath = '/s/pages/new'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->svg());
        $file = new UploadedFile($path, $filename, 'image/svg+xml', null, true);

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            ['path' => $folder],
            ['files' => [$file]],
            $this->ajaxHeaders($refererPath),
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

    private function uploadPng(string $filename, string $folder = '', ?string $decision = null, ?string $pngSeed = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lf-media');
        $this->assertIsString($path);
        file_put_contents($path, $this->png($pngSeed ?? $filename.'|'.$folder.'|'.bin2hex(random_bytes(2))));
        $file = new UploadedFile($path, $filename, 'image/png', null, true);

        $params = ['path' => $folder];
        if (null !== $decision) {
            $params['decision'] = $decision;
        }

        $this->client->request(
            Request::METHOD_POST,
            '/s/media-assets-basic/images/upload',
            $params,
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

    private function png(?string $seed = null): string
    {
        $seed ??= bin2hex(random_bytes(4));
        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(1, 1);
            $this->assertNotFalse($image);
            $hash  = crc32($seed);
            $color = imagecolorallocate($image, ($hash >> 16) & 0xFF, ($hash >> 8) & 0xFF, $hash & 0xFF);
            $this->assertNotFalse($color);
            imagesetpixel($image, 0, 0, $color);
            ob_start();
            imagepng($image);
            imagedestroy($image);
            $png = ob_get_clean();
            $this->assertIsString($png);

            return $png;
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);

        return $png.$seed;
    }

    private function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="8"><rect width="12" height="8" fill="#000"/></svg>';
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
