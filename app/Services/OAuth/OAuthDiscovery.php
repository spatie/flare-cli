<?php

namespace App\Services\OAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class OAuthDiscovery
{
    /** @var array<string, OAuthMetadata> */
    private array $metadata = [];

    public function metadata(string $appUrl): OAuthMetadata
    {
        $appUrl = rtrim($appUrl, '/');

        return $this->metadata[$appUrl] ??= $this->fetch($appUrl);
    }

    private function fetch(string $appUrl): OAuthMetadata
    {
        $url = "{$appUrl}/.well-known/oauth-authorization-server";

        try {
            $response = Http::acceptJson()->get($url);
        } catch (ConnectionException $exception) {
            throw new OAuthException("Could not discover Flare OAuth endpoints at {$url}: {$exception->getMessage()}");
        }

        if (! $response->successful()) {
            throw new OAuthException("Could not discover Flare OAuth endpoints at {$url} (HTTP {$response->status()}).");
        }

        try {
            return OAuthMetadata::fromArray($response->json() ?? []);
        } catch (InvalidArgumentException $exception) {
            throw new OAuthException("Invalid OAuth metadata from {$url}: {$exception->getMessage()}");
        }
    }
}
