<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception;

final class RenditionBusyException extends \RuntimeException
{
    public function __construct(
        private readonly int $retryAfterSeconds = 5,
        string $message = 'Rendition generation is busy or timed out.',
    ) {
        parent::__construct($message);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
