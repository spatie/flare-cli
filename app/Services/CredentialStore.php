<?php

namespace App\Services;

use App\Services\OAuth\OAuthException;
use App\Services\OAuth\TokenRecord;
use App\Services\OAuth\TokenRefresher;

class CredentialStore
{
    private string $configPath;

    public function __construct(
        private readonly FlareUrlResolver $urlResolver = new FlareUrlResolver
    ) {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';

        $this->configPath = "{$home}/.flare/config.json";
    }

    public function getToken(): ?string
    {
        $entry = $this->readEntries()[$this->urlResolver->getHostKey()] ?? null;

        if (is_string($entry)) {
            return $entry;
        }

        if (TokenRecord::looksLikeRecord($entry)) {
            return TokenRecord::fromArray($entry)->accessToken;
        }

        return null;
    }

    public function setToken(string $token): void
    {
        $this->writeEntry($this->urlResolver->getHostKey(), $token);
    }

    public function getRecord(): ?TokenRecord
    {
        $entry = $this->readEntries()[$this->urlResolver->getHostKey()] ?? null;

        if (! TokenRecord::looksLikeRecord($entry)) {
            return null;
        }

        return TokenRecord::fromArray($entry);
    }

    public function getAccessToken(): ?string
    {
        $entry = $this->readEntries()[$this->urlResolver->getHostKey()] ?? null;

        if (is_string($entry)) {
            return $entry;
        }

        if (! TokenRecord::looksLikeRecord($entry)) {
            return null;
        }

        $record = TokenRecord::fromArray($entry);

        return $this->withConfigLock(function () use ($record) {
            $current = $this->getRecord() ?? $record;
            $refreshed = app(TokenRefresher::class)->refreshIfNeeded($current);

            if ($refreshed !== $current) {
                $this->writeEntry($this->urlResolver->getHostKey(), $refreshed->toArray());
            }

            return $refreshed->accessToken;
        });
    }

    public function forceRefresh(): bool
    {
        $record = $this->getRecord();

        if ($record === null) {
            return false;
        }

        return $this->withConfigLock(function () use ($record) {
            try {
                $refreshed = app(TokenRefresher::class)->refresh($this->getRecord() ?? $record);
                $this->writeEntry($this->urlResolver->getHostKey(), $refreshed->toArray());

                return true;
            } catch (OAuthException) {
                return false;
            }
        });
    }

    public function setRecord(TokenRecord $record): void
    {
        $this->writeEntry($this->urlResolver->getHostKey(), $record->toArray());
    }

    public function flush(): void
    {
        if (! file_exists($this->configPath)) {
            return;
        }

        $this->ensureConfigDirectoryExists();

        $entries = $this->readEntries();
        unset($entries[$this->urlResolver->getHostKey()]);

        $this->writeEntries($entries);
    }

    public function flushAll(): void
    {
        if (! file_exists($this->configPath)) {
            return;
        }

        $this->ensureConfigDirectoryExists();
        $this->writeEntries([]);
    }

    /**
     * @return array<int, string>
     */
    public function getConfiguredHosts(): array
    {
        return array_keys($this->readEntries());
    }

    private function ensureConfigDirectoryExists(): void
    {
        $directory = dirname($this->configPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        if (! file_exists($this->configPath)) {
            return [];
        }

        return json_decode(file_get_contents($this->configPath), true) ?? [];
    }

    /**
     * @return array<string, string|array<string, mixed>>
     */
    private function readEntries(): array
    {
        $data = $this->readConfig();
        $entries = $data['tokens'] ?? [];

        if (! is_array($entries)) {
            $entries = [];
        }

        $entries = array_filter(
            $entries,
            fn (mixed $entry, mixed $host): bool => is_string($host) && self::isValidEntry($entry),
            ARRAY_FILTER_USE_BOTH,
        );

        if (
            isset($data['token']) &&
            is_string($data['token']) &&
            $data['token'] !== '' &&
            ! array_key_exists('flareapp.io', $entries)
        ) {
            $entries['flareapp.io'] = $data['token'];
        }

        ksort($entries);

        return $entries;
    }

    private function writeEntry(string $host, string|array $entry): void
    {
        $this->ensureConfigDirectoryExists();

        $entries = $this->readEntries();
        $entries[$host] = $entry;

        $this->writeEntries($entries);
    }

    /**
     * @param  array<string, string|array<string, mixed>>  $entries
     */
    private function writeEntries(array $entries): void
    {
        ksort($entries);

        $data = $this->readConfig();
        unset($data['token']);
        $data['tokens'] = $entries === [] ? (object) [] : $entries;

        file_put_contents(
            $this->configPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function isValidEntry(mixed $entry): bool
    {
        if (is_string($entry)) {
            return $entry !== '';
        }

        return TokenRecord::looksLikeRecord($entry);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withConfigLock(callable $callback): mixed
    {
        $this->ensureConfigDirectoryExists();
        $lockPath = $this->configPath.'.lock';
        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            return $callback();
        }

        flock($handle, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
