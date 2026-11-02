<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\RenditionBusyException;

/**
 * Serialises rendition generation across CLI and HTTP (one image at a time).
 */
final class RenditionLock
{
    public const DEFAULT_TIMEOUT_SECONDS     = 20;
    public const DEFAULT_RETRY_AFTER_SECONDS = 5;

    /** @var resource|null */
    private $handle;

    public function __construct(
        private readonly PathsHelper $pathsHelper,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $retryAfterSeconds = self::DEFAULT_RETRY_AFTER_SECONDS,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(float): T $callback Receives a microtime deadline
     *
     * @return T
     */
    public function withLock(callable $callback, bool $failFast = false): mixed
    {
        $this->acquire($failFast);
        $deadline = microtime(true) + $this->timeoutSeconds;

        try {
            return $callback($deadline);
        } finally {
            $this->release();
        }
    }

    public function assertWithinDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RenditionBusyException($this->retryAfterSeconds, 'Rendition generation timed out.');
        }
    }

    private function acquire(bool $failFast): void
    {
        $path = $this->lockPath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RenditionBusyException($this->retryAfterSeconds, 'Unable to create rendition lock directory.');
        }

        $handle = fopen($path, 'c+');
        if (false === $handle) {
            throw new RenditionBusyException($this->retryAfterSeconds, 'Unable to open rendition lock.');
        }

        $flags = LOCK_EX;
        if ($failFast) {
            $flags |= LOCK_NB;
        }

        if (!flock($handle, $flags)) {
            fclose($handle);
            throw new RenditionBusyException($this->retryAfterSeconds, 'Rendition generation is already running.');
        }

        $this->handle = $handle;
    }

    private function release(): void
    {
        if (null === $this->handle) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    private function lockPath(): string
    {
        return rtrim($this->pathsHelper->getSystemPath('tmp', true), '/').'/lf_media_rendition.lock';
    }
}
