<?php

namespace App\Services\OAuth;

final class DevicePollResult
{
    public function __construct(
        public readonly ?TokenRecord $record,
        public readonly ?string $error,
        public readonly ?string $errorDescription = null,
    ) {}

    public static function success(TokenRecord $record): self
    {
        return new self($record, null);
    }

    public static function error(string $code, ?string $description = null): self
    {
        return new self(null, $code, $description);
    }

    public function isPending(): bool
    {
        return $this->error === 'authorization_pending';
    }

    public function isSlowDown(): bool
    {
        return $this->error === 'slow_down';
    }

    public function isFatal(): bool
    {
        return $this->error !== null && ! $this->isPending() && ! $this->isSlowDown();
    }
}
