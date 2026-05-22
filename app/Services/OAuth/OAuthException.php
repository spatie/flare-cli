<?php

namespace App\Services\OAuth;

use RuntimeException;

class OAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorDescription = null,
    ) {
        parent::__construct($message);
    }
}
