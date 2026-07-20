<?php

namespace App\Services;

class FlareUrlResolver
{
    /** @var array<string, string> */
    private const HOST_ALIASES = [
        'ingress-staging.flareapp.io' => 'staging.flareapp.io',
    ];

    private const DEFAULT_API_BASE_URL = 'https://flareapp.io/api';

    private const DEFAULT_APP_URL = 'https://flareapp.io';

    private const DEFAULT_HOST_KEY = 'flareapp.io';

    public function __construct(
        private readonly ?string $baseUrl = null,
    ) {}

    public function getApiBaseUrl(): string
    {
        return $this->normalizeApiBaseUrl($this->configuredBaseUrl());
    }

    public function getHostKey(): string
    {
        $parts = parse_url($this->getApiBaseUrl());

        if (! is_array($parts) || ! isset($parts['host'])) {
            return self::DEFAULT_HOST_KEY;
        }

        $host = strtolower($parts['host']);

        if (! isset($parts['port'])) {
            return $host;
        }

        return "{$host}:{$parts['port']}";
    }

    public function getAppUrl(): string
    {
        $parts = parse_url($this->getApiBaseUrl());

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return self::DEFAULT_APP_URL;
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ":{$parts['port']}";
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($path === '' || $path === 'api') {
            return $origin;
        }

        if (str_ends_with($path, '/api')) {
            $path = substr($path, 0, -4);
        }

        return $path === '' ? $origin : "{$origin}/{$path}";
    }

    private function configuredBaseUrl(): ?string
    {
        if ($this->baseUrl !== null) {
            return $this->baseUrl;
        }

        $baseUrl = $_SERVER['FLARE_BASE_URL'] ?? getenv('FLARE_BASE_URL') ?: null;

        if ($baseUrl === null) {
            return null;
        }

        $baseUrl = trim($baseUrl);

        return $baseUrl === '' ? null : $baseUrl;
    }

    private function normalizeApiBaseUrl(?string $baseUrl): string
    {
        $baseUrl ??= self::DEFAULT_API_BASE_URL;

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return self::DEFAULT_API_BASE_URL;
        }

        $normalizedHost = $this->canonicalHost(strtolower($parts['host']));

        $normalized = strtolower($parts['scheme']).'://'.$normalizedHost;

        if (isset($parts['port'])) {
            $normalized .= ":{$parts['port']}";
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($path === '') {
            return "{$normalized}/api";
        }

        return "{$normalized}/{$path}";
    }

    private function canonicalHost(string $host): string
    {
        return self::HOST_ALIASES[$host] ?? $host;
    }
}
