<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final readonly class UrlImageImporter
{
    private const TIMEOUT_SECONDS = 10;

    private const MAX_REDIRECTS = 3;

    private const MAX_DOWNLOAD_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private PublicHostGuard $guard,
        private ImageStorage $storage,
    ) {
    }

    public function import(string $directory, string $url, string $relativeDirectory = '', bool $allowSvg = false): ListedImage
    {
        $url      = trim($url);
        $maxBytes = min(self::MAX_DOWNLOAD_BYTES, IniSize::toBytes(IniSize::uploadMaxFilesize()));
        $this->guard->safeAddresses($url);

        $downloaded = $this->download($url, $maxBytes);
        $name       = $this->nameFromUrl($downloaded['url']);

        return $this->storage->store($directory, $name, $downloaded['body'], $relativeDirectory, $allowSvg);
    }

    /**
     * @return array{url: string, body: string}
     */
    private function download(string $url, int $maxBytes): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            throw new ImageRejectedException(Translation::ERROR_SCHEME);
        }

        $host      = trim($parts['host'], '[]');
        $port      = isset($parts['port']) ? (int) $parts['port'] : ('https' === strtolower($parts['scheme']) ? 443 : 80);
        $addresses = $this->guard->safeAddresses($url);
        $pinned    = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        $temp = fopen('php://temp', 'r+');
        if (false === $temp) {
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        $client = new Client([
            RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::HEADERS         => ['User-Agent' => 'Mautic Media Assets'],
            RequestOptions::HTTP_ERRORS     => false,
            RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
        ]);

        try {
            $response = $client->request('GET', $url, [
                RequestOptions::SINK            => $temp,
                RequestOptions::ALLOW_REDIRECTS => [
                    'max'             => self::MAX_REDIRECTS,
                    'protocols'       => ['http', 'https'],
                    'strict'          => true,
                    'referer'         => false,
                    'track_redirects' => true,
                    'on_redirect'     => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                        $this->guard->safeAddresses((string) $uri);
                    },
                ],
                RequestOptions::ON_HEADERS => function (ResponseInterface $response) use ($maxBytes): void {
                    $this->assertContentLengthWithinLimit($response, $maxBytes);
                },
                'progress' => function (int $downloadTotal, int $downloadedBytes) use ($maxBytes): void {
                    if ($maxBytes > 0 && $downloadedBytes > $maxBytes) {
                        throw new ImageRejectedException(Translation::ERROR_TOO_LARGE, ['%limit%' => IniSize::uploadMaxFilesize()]);
                    }
                },
                'curl' => [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_RESOLVE   => [$host.':'.$port.':'.$pinned],
                ],
            ]);
        } catch (TooManyRedirectsException) {
            fclose($temp);
            throw new ImageRejectedException(Translation::ERROR_REDIRECTS);
        } catch (ConnectException|RequestException $exception) {
            fclose($temp);
            $previous = $exception->getPrevious();
            if ($previous instanceof ImageRejectedException) {
                throw $previous;
            }
            $errno = $exception->getHandlerContext()['errno'] ?? null;
            if (CURLE_OPERATION_TIMEDOUT === $errno) {
                throw new ImageRejectedException(Translation::ERROR_TIMEOUT);
            }

            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        } catch (TransferException $exception) {
            fclose($temp);
            $previous = $exception->getPrevious();
            if ($previous instanceof ImageRejectedException) {
                throw $previous;
            }

            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            fclose($temp);
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        rewind($temp);
        $body = stream_get_contents($temp);
        fclose($temp);
        if (!is_string($body)) {
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            throw new ImageRejectedException(Translation::ERROR_TOO_LARGE, ['%limit%' => IniSize::uploadMaxFilesize()]);
        }

        return [
            'url'  => $this->effectiveUrl($url, $response),
            'body' => $body,
        ];
    }

    private function assertContentLengthWithinLimit(ResponseInterface $response, int $maxBytes): void
    {
        $length = $response->getHeaderLine('Content-Length');
        if ($maxBytes > 0 && '' !== $length && is_numeric($length) && (int) $length > $maxBytes) {
            throw new ImageRejectedException(Translation::ERROR_TOO_LARGE, ['%limit%' => IniSize::uploadMaxFilesize()]);
        }
    }

    private function effectiveUrl(string $url, ResponseInterface $response): string
    {
        $history = $response->getHeader('X-Guzzle-Redirect-History');
        if ([] !== $history) {
            return end($history);
        }

        return $url;
    }

    private function nameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && '' !== basename($path) ? basename($path) : 'image';
    }
}
