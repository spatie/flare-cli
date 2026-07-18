<?php

namespace App\Services\OAuth;

use Closure;

class DeviceLoginFlow
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        private readonly OAuthHttpClient $client,
        private readonly array $scopes,
    ) {}

    /**
     * @param  callable(DeviceAuthorization): void  $announce
     * @param  ?Closure(int): void  $sleeper
     * @param  ?Closure(): int  $clock
     */
    public function run(
        callable $announce,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
    ): TokenRecord {
        $sleeper ??= fn (int $seconds) => sleep($seconds);
        $clock ??= fn () => time();

        $auth = $this->client->requestDeviceCode($this->scopes);
        $announce($auth);

        $interval = $auth->interval;
        $deadline = $clock() + $auth->expiresIn;

        while ($clock() < $deadline) {
            $sleeper($interval);

            $result = $this->client->pollDeviceCode($auth->deviceCode, $this->scopes);

            if ($result->record !== null) {
                return $result->record;
            }

            if ($result->isPending()) {
                continue;
            }

            if ($result->isSlowDown()) {
                $interval += 5;

                continue;
            }

            throw new OAuthException(
                "Device authorization failed: {$result->error}"
                    .($result->errorDescription ? " — {$result->errorDescription}" : ''),
                errorCode: $result->error,
                errorDescription: $result->errorDescription,
            );
        }

        throw new OAuthException('Device code expired before authorization completed.');
    }
}
