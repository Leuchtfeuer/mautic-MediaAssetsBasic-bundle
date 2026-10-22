<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\HostResolver;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\PublicHostGuard;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\UrlImageImporter;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;

final class UrlImageImporterTest extends TestCase
{
    public function testNonSuccessResponseIncludesHttpStatusInError(): void
    {
        $importer = $this->importer(new MockHandler([
            new Response(429, [], 'rate limited'),
        ]));

        try {
            $importer->import(sys_get_temp_dir(), 'https://example.com/photo.jpg');
            $this->fail('Expected ImageRejectedException');
        } catch (ImageRejectedException $exception) {
            $this->assertSame(Translation::ERROR_DOWNLOAD_HTTP, $exception->getMessage());
            $this->assertSame(['%status%' => '429 Too Many Requests'], $exception->getParameters());
        }
    }

    public function testGenericDownloadErrorWhenNoHttpResponse(): void
    {
        $importer = $this->importer(new MockHandler([
            new ConnectException(
                'connection refused',
                new Request('GET', 'https://example.com/photo.jpg'),
            ),
        ]));

        try {
            $importer->import(sys_get_temp_dir(), 'https://example.com/photo.jpg');
            $this->fail('Expected ImageRejectedException');
        } catch (ImageRejectedException $exception) {
            $this->assertSame(Translation::ERROR_DOWNLOAD, $exception->getMessage());
            $this->assertSame([], $exception->getParameters());
        }
    }

    private function importer(MockHandler $handler): UrlImageImporter
    {
        $guard = new PublicHostGuard(new class() implements HostResolver {
            public function resolve(string $host): array
            {
                return ['203.0.113.10'];
            }
        });

        return new UrlImageImporter(
            $guard,
            new ImageStorage(new ImageInspector()),
            new Client(['handler' => HandlerStack::create($handler)]),
        );
    }
}
