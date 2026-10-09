<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Psr\Http\Message\ResponseInterface;

final readonly class UrlImageImporter
{
    private const TIMEOUT_SECONDS = 10;

    private const MAX_REDIRECTS = 3;

    public function __construct(
        private PublicHostGuard $guard,
        private ImageStorage $storage,
    ) {
    }

    public function import(string $directory, string $url, string $relativeDirectory = ''): ListedImage
    {
        $current = trim($url);
        $max     = IniSize::toBytes(IniSize::uploadMaxFilesize());

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $addresses = $this->guard->safeAddresses($current);
            $response  = $this->download($current, $addresses[0], $max);
            if (null !== $response['location']) {
                if (self::MAX_REDIRECTS === $hop) {
                    throw new ImageRejectedException(Translation::ERROR_REDIRECTS);
                }
                $current = $this->resolveLocation($current, $response['location']);
                continue;
            }

            $name = $this->nameFromUrl($current);

            return $this->storage->store($directory, $name, $response['body'], $relativeDirectory);
        }

        throw new ImageRejectedException(Translation::ERROR_REDIRECTS);
    }

    /**
     * @return array{body: string, location: ?string}
     */
    private function download(string $url, string $ip, int $maxBytes): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            throw new ImageRejectedException(Translation::ERROR_SCHEME);
        }

        $host   = trim($parts['host'], '[]');
        $port   = isset($parts['port']) ? (int) $parts['port'] : ('https' === strtolower($parts['scheme']) ? 443 : 80);
        $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $client = new Client([
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::HEADERS         => ['User-Agent' => 'Mautic Media Assets'],
            RequestOptions::HTTP_ERRORS     => false,
            RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
        ]);

        try {
            $response = $client->request('GET', $url, [
                RequestOptions::STREAM  => true,
                'curl'                  => [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_RESOLVE   => [$host.':'.$port.':'.$pinned],
                ],
            ]);
        } catch (ConnectException|RequestException $exception) {
            $errno = $exception->getHandlerContext()['errno'] ?? null;
            if (CURLE_OPERATION_TIMEDOUT === $errno) {
                throw new ImageRejectedException(Translation::ERROR_TIMEOUT);
            }

            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        } catch (TransferException) {
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            $location = $response->getHeaderLine('Location');
            $response->getBody()->close();
            if ('' === $location) {
                throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
            }

            return ['body' => '', 'location' => $location];
        }

        if ($status < 200 || $status >= 300) {
            $response->getBody()->close();
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        return ['body' => $this->readBody($response, $maxBytes), 'location' => null];
    }

    private function readBody(ResponseInterface $response, int $maxBytes): string
    {
        $source = $response->getBody();
        $length = $response->getHeaderLine('Content-Length');
        if ($maxBytes > 0 && is_numeric($length) && (int) $length > $maxBytes) {
            $source->close();
            throw new ImageRejectedException(Translation::ERROR_TOO_LARGE, ['%limit%' => IniSize::uploadMaxFilesize()]);
        }

        $temp = fopen('php://temp', 'r+');
        if (false === $temp) {
            $source->close();
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        $target = Utils::streamFor($temp);
        $read   = 0;
        while (!$source->eof()) {
            $chunk = $source->read(8192);
            if ('' === $chunk) {
                break;
            }

            $read += strlen($chunk);
            if ($maxBytes > 0 && $read > $maxBytes) {
                $source->close();
                $target->close();
                throw new ImageRejectedException(Translation::ERROR_TOO_LARGE, ['%limit%' => IniSize::uploadMaxFilesize()]);
            }

            $target->write($chunk);
        }

        $source->close();
        $target->rewind();
        $contents = $target->getContents();
        $target->close();

        return $contents;
    }

    private function resolveLocation(string $current, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';

        return $origin.rtrim(dirname($path), '/').'/'.$location;
    }

    private function nameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && '' !== basename($path) ? basename($path) : 'image';
    }
}
