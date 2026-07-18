<?php

namespace App\Services\OAuth;

final class PkceCodes
{
    public static function verifier(int $bytes = 32): string
    {
        return self::base64url(random_bytes($bytes));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    public static function state(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
