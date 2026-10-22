<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception;

final class ImageRejectedException extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(string $messageKey, private readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }

    /**
     * @return array<string, string>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
