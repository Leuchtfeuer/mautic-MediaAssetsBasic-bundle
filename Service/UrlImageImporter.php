<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;

final readonly class UrlImageImporter
{
    private const TIMEOUT_SECONDS = 10;

    private const MAX_REDIRECTS = 3;

    public function __construct(
        private PublicHostGuard $guard,
        private ImageStorage $storage,
    ) {
    }

    public function import(string $directory, string $url): ListedImage
    {
        $current = trim($url);
        $max     = min(8 * 1024 * 1024, IniSize::toBytes(IniSize::uploadMaxFilesize()));

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

            return $this->storage->store($directory, $name, $response['body']);
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

        $host = trim($parts['host'], '[]');
        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === strtolower($parts['scheme']) ? 443 : 80);

        $handle = curl_init($url);
        if (false === $handle) {
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        $body    = '';
        $headers = '';
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => 'Mautic Media Assets',
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $header) use (&$headers): int {
                unset($curl);
                $headers .= $header;

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use (&$body, $maxBytes): int {
                unset($curl);
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        curl_setopt($handle, CURLOPT_RESOLVE, [$host.':'.$port.':'.$pinned]);

        $ok = curl_exec($handle);
        if (strlen($body) > $maxBytes) {
            curl_close($handle);
            throw new ImageRejectedException(Translation::ERROR_TOO_LARGE, ['%limit%' => IniSize::uploadMaxFilesize()]);
        }

        if (false === $ok) {
            $error = curl_errno($handle);
            curl_close($handle);
            if (CURLE_OPERATION_TIMEDOUT === $error) {
                throw new ImageRejectedException(Translation::ERROR_TIMEOUT);
            }

            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($status >= 300 && $status < 400) {
            if (1 !== preg_match('/^Location:\s*(\S+)/mi', $headers, $matches)) {
                throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
            }

            return ['body' => '', 'location' => $matches[1]];
        }

        if ($status < 200 || $status >= 300) {
            throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
        }

        return ['body' => $body, 'location' => null];
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
