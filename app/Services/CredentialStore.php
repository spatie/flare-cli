<?php

namespace App\Services;

use App\Services\OAuth\OAuthException;
use App\Services\OAuth\TokenRecord;
use App\Services\OAuth\TokenRefresher;
use RuntimeException;
use Spatie\OpenApiCli\Exceptions\AuthenticationException;

class CredentialStore
{
    private string $configPath;

    private bool $permissionsVerified = false;

    public function __construct(
        private readonly FlareUrlResolver $urlResolver = new FlareUrlResolver,
    ) {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';

        $this->configPath = "{$home}/.flare/config.json";
    }

    public function getToken(): ?string
    {
        $entry = $this->activeEntry();

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
        $this->storeProfile(null, $this->urlResolver->getApiBaseUrl(), $token, replaceActive: true);
    }

    public function getRecord(): ?TokenRecord
    {
        $entry = $this->activeEntry();

        if (! TokenRecord::looksLikeRecord($entry)) {
            return null;
        }

        return TokenRecord::fromArray($entry);
    }

    public function getAccessToken(): ?string
    {
        $this->migrateActiveLegacyEntry();

        $entry = $this->activeEntry();

        if (is_string($entry)) {
            return $entry;
        }

        if (! TokenRecord::looksLikeRecord($entry)) {
            return null;
        }

        return $this->withConfigLock(function () {
            $current = $this->getRecord();

            if ($current === null) {
                return null;
            }

            $refresher = app(TokenRefresher::class);

            if (! $refresher->shouldRefresh($current)) {
                return $current->accessToken;
            }

            return $this->refreshLocked($current, $refresher)->accessToken;
        });
    }

    private function refreshLocked(TokenRecord $current, TokenRefresher $refresher): TokenRecord
    {
        if ($current->refreshPending) {
            throw new AuthenticationException(
                'The previous refresh result is unknown. Log in again to avoid replaying a single-use refresh token.',
                hint: 'Run `flare login` to create a new connection.',
            );
        }

        $pending = $current->markRefreshPending();
        $this->writeActiveEntry($pending->toArray());

        try {
            $refreshed = $refresher->refresh($pending);
            $this->writeActiveEntry($refreshed->toArray());

            return $refreshed;
        } catch (OAuthException $exception) {
            if ($exception->errorCode === 'invalid_grant') {
                throw new AuthenticationException(
                    'Your Flare OAuth session could not be refreshed and may have been revoked or changed.',
                    hint: 'Run `flare login` to create a new connection.',
                );
            }

            return $pending;
        }
    }

    public function forceRefresh(): bool
    {
        $this->migrateActiveLegacyEntry();

        if ($this->getRecord() === null) {
            return false;
        }

        return $this->withConfigLock(function () {
            $current = $this->getRecord();

            if ($current === null) {
                return false;
            }

            $refreshed = $this->refreshLocked($current, app(TokenRefresher::class));

            return $refreshed !== $current && ! $refreshed->refreshPending;
        });
    }

    public function setRecord(TokenRecord $record): void
    {
        $issuer = $record->issuer ?? rtrim($this->urlResolver->getAppUrl(), '/');
        $apiBaseUrl = $record->apiBaseUrl ?? $this->urlResolver->getApiBaseUrl();

        $this->storeProfile($issuer, $apiBaseUrl, $record->toArray());
    }

    public function flush(): void
    {
        if (! file_exists($this->configPath)) {
            return;
        }

        $this->withConfigLock(function () {
            $data = $this->readConfig();
            $apiBaseUrl = $this->urlResolver->getApiBaseUrl();
            $profileKey = $data['active_profiles'][$apiBaseUrl] ?? null;

            if (is_string($profileKey)) {
                unset($data['profiles'][$profileKey], $data['active_profiles'][$apiBaseUrl]);
            }

            unset($data['tokens'][$this->urlResolver->getHostKey()]);

            $this->writeConfig($data);
        });
    }

    public function flushAll(): void
    {
        if (! file_exists($this->configPath)) {
            return;
        }

        $this->withConfigLock(function () {
            $data = $this->readConfig();
            unset($data['token'], $data['tokens']);
            $data['profiles'] = (object) [];
            $data['active_profiles'] = (object) [];

            $this->writeConfig($data);
        });
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     issuer: ?string,
     *     api_base_url: string,
     *     oauth: bool,
     *     type: string,
     *     active: bool
     * }>
     */
    public function getConfiguredProfiles(): array
    {
        $profiles = [];

        foreach ($this->profileRows($this->readConfig()) as $row) {
            $profiles[] = [
                'key' => $row['key'],
                'issuer' => $row['issuer'],
                'api_base_url' => $row['api_base_url'],
                'oauth' => $row['oauth'],
                'type' => match (true) {
                    $row['oauth'] && $row['legacy'] => 'oauth (legacy profile)',
                    $row['oauth'] => 'oauth',
                    default => 'personal token',
                },
                'active' => $row['active'],
            ];
        }

        return $profiles;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     issuer: ?string,
     *     api_base_url: string,
     *     record: TokenRecord,
     *     active: bool
     * }>
     */
    public function getOAuthProfiles(): array
    {
        $records = [];

        foreach ($this->profileRows($this->readConfig()) as $row) {
            if (! $row['oauth']) {
                continue;
            }

            $records[] = [
                'key' => $row['key'],
                'issuer' => $row['issuer'],
                'api_base_url' => $row['api_base_url'],
                'record' => TokenRecord::fromArray($row['credential']),
                'active' => $row['active'],
            ];
        }

        return $records;
    }

    public function forgetProfile(string $key): void
    {
        if (! file_exists($this->configPath)) {
            return;
        }

        $this->withConfigLock(function () use ($key) {
            $data = $this->readConfig();

            if (str_starts_with($key, 'legacy:')) {
                unset($data['tokens'][substr($key, strlen('legacy:'))]);
            } else {
                unset($data['profiles'][$key]);

                foreach (($data['active_profiles'] ?? []) as $apiBaseUrl => $activeKey) {
                    if ($activeKey === $key) {
                        unset($data['active_profiles'][$apiBaseUrl]);
                    }
                }
            }

            $this->writeConfig($data);
        });
    }

    public function forgetNonOAuthProfiles(): void
    {
        foreach ($this->getConfiguredProfiles() as $profile) {
            if ($profile['oauth']) {
                continue;
            }

            $this->forgetProfile($profile['key']);
        }
    }

    /**
     * @param  string|array<string, mixed>  $credential
     */
    private function storeProfile(
        ?string $issuer,
        string $apiBaseUrl,
        string|array $credential,
        bool $replaceActive = false,
    ): void {
        $this->withConfigLock(function () use ($issuer, $apiBaseUrl, $credential, $replaceActive) {
            $data = $this->readConfig();
            $key = self::profileKey($issuer, $apiBaseUrl);
            $oldKey = $data['active_profiles'][$apiBaseUrl] ?? null;

            if ($replaceActive && is_string($oldKey) && $oldKey !== $key) {
                unset($data['profiles'][$oldKey]);
            }

            $data['profiles'][$key] = [
                'issuer' => $issuer,
                'api_base_url' => $apiBaseUrl,
                'credential' => $credential,
            ];
            $data['active_profiles'][$apiBaseUrl] = $key;

            unset($data['tokens'][$this->urlResolver->getHostKey()]);

            $this->writeConfig($data);
        });
    }

    private function migrateActiveLegacyEntry(): void
    {
        $data = $this->readConfig();
        $apiBaseUrl = $this->urlResolver->getApiBaseUrl();

        if (isset($data['active_profiles'][$apiBaseUrl])) {
            return;
        }

        $host = $this->urlResolver->getHostKey();
        $legacyEntry = $this->legacyEntries($data)[$host] ?? null;

        if ($legacyEntry === null) {
            return;
        }

        $issuer = null;

        if (TokenRecord::looksLikeRecord($legacyEntry)) {
            $issuer = rtrim($this->urlResolver->getAppUrl(), '/');

            $legacyEntry = TokenRecord::fromArray($legacyEntry)
                ->withProfile($issuer, $apiBaseUrl)
                ->toArray();
        }

        $this->storeProfile($issuer, $apiBaseUrl, $legacyEntry);
    }

    private function activeEntry(): mixed
    {
        $data = $this->readConfig();
        $apiBaseUrl = $this->urlResolver->getApiBaseUrl();
        $key = $data['active_profiles'][$apiBaseUrl] ?? null;

        if (is_string($key)) {
            $profile = $this->validProfiles($data)[$key] ?? null;

            if ($profile !== null) {
                return $profile['credential'];
            }
        }

        return $this->legacyEntries($data)[$this->urlResolver->getHostKey()] ?? null;
    }

    /**
     * @param  string|array<string, mixed>  $entry
     */
    private function writeActiveEntry(string|array $entry): void
    {
        $data = $this->readConfig();
        $apiBaseUrl = $this->urlResolver->getApiBaseUrl();
        $key = $data['active_profiles'][$apiBaseUrl] ?? null;

        if (! is_string($key) || ! isset($data['profiles'][$key])) {
            throw new RuntimeException("No active credential profile exists for {$apiBaseUrl}.");
        }

        $data['profiles'][$key]['credential'] = $entry;

        $this->writeConfig($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{
     *     key: string,
     *     issuer: ?string,
     *     api_base_url: string,
     *     credential: string|array<string, mixed>,
     *     oauth: bool,
     *     legacy: bool,
     *     active: bool
     * }>
     */
    private function profileRows(array $data): array
    {
        $rows = [];
        $activeProfiles = is_array($data['active_profiles'] ?? null) ? $data['active_profiles'] : [];

        foreach ($this->validProfiles($data) as $key => $profile) {
            $rows[] = [
                'key' => $key,
                'issuer' => $profile['issuer'],
                'api_base_url' => $profile['api_base_url'],
                'credential' => $profile['credential'],
                'oauth' => TokenRecord::looksLikeRecord($profile['credential']),
                'legacy' => false,
                'active' => ($activeProfiles[$profile['api_base_url']] ?? null) === $key,
            ];
        }

        foreach ($this->legacyEntries($data) as $host => $credential) {
            $rows[] = [
                'key' => "legacy:{$host}",
                'issuer' => null,
                'api_base_url' => $this->legacyApiBaseUrl($host),
                'credential' => $credential,
                'oauth' => TokenRecord::looksLikeRecord($credential),
                'legacy' => true,
                'active' => $host === $this->urlResolver->getHostKey(),
            ];
        }

        usort(
            $rows,
            fn (array $first, array $second): int => $first['api_base_url'] <=> $second['api_base_url'],
        );

        return $rows;
    }

    private function ensureConfigDirectoryExists(): void
    {
        $directory = dirname($this->configPath);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create credential directory {$directory}.");
        }

        $this->tightenPermissions($directory, 0700);
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        if (! file_exists($this->configPath)) {
            return [];
        }

        if (! $this->permissionsVerified) {
            $this->ensureConfigDirectoryExists();
            $this->tightenPermissions($this->configPath, 0600);
            $this->permissionsVerified = true;
        }

        $contents = file_get_contents($this->configPath);

        if ($contents === false) {
            return [];
        }

        return json_decode($contents, true) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function writeConfig(array $data): void
    {
        $this->ensureConfigDirectoryExists();
        unset($data['token']);

        if (isset($data['tokens']) && is_array($data['tokens']) && $data['tokens'] === []) {
            unset($data['tokens']);
        }

        foreach (['profiles', 'active_profiles'] as $key) {
            $value = $data[$key] ?? [];

            if (is_array($value)) {
                ksort($value);
                $data[$key] = $value === [] ? (object) [] : $value;
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new RuntimeException('Could not encode Flare credentials.');
        }

        $directory = dirname($this->configPath);
        $temporaryPath = "{$directory}/.config.".bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporaryPath, 'x+b');

        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary credential file.');
        }

        try {
            $this->tightenPermissions($temporaryPath, 0600);

            if (fwrite($handle, $json) !== strlen($json)) {
                throw new RuntimeException('Could not write the complete credential file.');
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Could not flush the credential file.');
            }

            if (! fsync($handle)) {
                throw new RuntimeException('Could not sync the credential file.');
            }

            fclose($handle);
            $handle = null;

            if (! rename($temporaryPath, $this->configPath)) {
                throw new RuntimeException('Could not atomically replace the credential file.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @return array<string, array{
     *     issuer: ?string,
     *     api_base_url: string,
     *     credential: string|array<string, mixed>
     * }>
     */
    private function validProfiles(array $data): array
    {
        $profiles = $data['profiles'] ?? [];

        if (! is_array($profiles)) {
            return [];
        }

        return array_filter(
            $profiles,
            function (mixed $profile, mixed $key): bool {
                if (! is_string($key) || ! is_array($profile)) {
                    return false;
                }

                if (! is_string($profile['api_base_url'] ?? null)) {
                    return false;
                }

                $issuer = $profile['issuer'] ?? null;

                if ($issuer !== null && ! is_string($issuer)) {
                    return false;
                }

                return self::isValidEntry($profile['credential'] ?? null);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, string|array<string, mixed>>
     */
    private function legacyEntries(array $data): array
    {
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
            isset($data['token'])
            && is_string($data['token'])
            && $data['token'] !== ''
            && ! array_key_exists('flareapp.io', $entries)
        ) {
            $entries['flareapp.io'] = $data['token'];
        }

        ksort($entries);

        return $entries;
    }

    private function legacyApiBaseUrl(string $host): string
    {
        if ($host === $this->urlResolver->getHostKey()) {
            return $this->urlResolver->getApiBaseUrl();
        }

        return "https://{$host}/api";
    }

    private static function profileKey(?string $issuer, string $apiBaseUrl): string
    {
        return hash('sha256', ($issuer ?? 'personal-token')."\0{$apiBaseUrl}");
    }

    private static function isValidEntry(mixed $entry): bool
    {
        if (is_string($entry)) {
            return $entry !== '';
        }

        return TokenRecord::looksLikeRecord($entry);
    }

    private function tightenPermissions(string $path, int $mode): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @chmod($path, $mode);

            return;
        }

        if (! chmod($path, $mode)) {
            throw new RuntimeException("Could not set secure permissions on {$path}.");
        }
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
            throw new RuntimeException('Could not open the credential lock file.');
        }

        $this->tightenPermissions($lockPath, 0600);

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException('Could not lock the credential file.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
