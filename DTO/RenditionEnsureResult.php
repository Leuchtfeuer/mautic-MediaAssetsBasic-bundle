<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO;

final readonly class RenditionEnsureResult
{
    public function __construct(
        public int $generated = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {
    }

    public function withGenerated(int $count = 1): self
    {
        return new self($this->generated + $count, $this->skipped, $this->failed);
    }

    public function withSkipped(int $count = 1): self
    {
        return new self($this->generated, $this->skipped + $count, $this->failed);
    }

    public function withFailed(int $count = 1): self
    {
        return new self($this->generated, $this->skipped, $this->failed + $count);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->generated + $other->generated,
            $this->skipped + $other->skipped,
            $this->failed + $other->failed,
        );
    }
}
