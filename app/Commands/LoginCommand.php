<?php

namespace App\Commands;

use App\Concerns\RendersBanner;
use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\DeviceAuthorization;
use App\Services\OAuth\DeviceLoginFlow;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\PkceLoginFlow;
use App\Services\OAuth\TokenRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class LoginCommand extends Command
{
    use RendersBanner;

    protected $signature = 'login
                            {--token : Paste a personal access token instead of using the browser flow}
                            {--device : Use the device code flow (for headless terminals)}
                            {--name= : Suggest a workstation connection name}
                            {--timeout=120 : Seconds to wait for the OAuth callback}';

    protected $description = 'Authenticate with Flare via OAuth, device code, or a personal access token';

    public function handle(
        CredentialStore $credentials,
        FlareUrlResolver $urlResolver,
        PkceLoginFlow $pkce,
        DeviceLoginFlow $device,
    ): int {
        $this->renderBanner($this->output);
        $this->showActiveContext($urlResolver);

        if ($this->option('token')) {
            if ($this->option('name') !== null) {
                $this->error('The --name option is only available for OAuth login.');

                return self::FAILURE;
            }

            return $this->loginWithPersonalAccessToken($credentials, $urlResolver);
        }

        $connectionName = $this->connectionName();

        if ($this->option('device')) {
            return $this->loginWithDeviceCode($credentials, $urlResolver, $device, $connectionName);
        }

        if (! $this->isInteractiveTerminal()) {
            $this->warn('Non-interactive terminal detected. Falling back to --device.');
            $this->newLine();

            return $this->loginWithDeviceCode($credentials, $urlResolver, $device, $connectionName);
        }

        return $this->loginWithBrowser($credentials, $urlResolver, $pkce, $connectionName);
    }

    private function isInteractiveTerminal(): bool
    {
        if ($this->input->isInteractive()) {
            return true;
        }

        return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    private function showActiveContext(FlareUrlResolver $urlResolver): void
    {
        $this->line("Active API base URL: <href={$urlResolver->getApiBaseUrl()}>{$urlResolver->getApiBaseUrl()}</>");
        $this->line("Active auth host: {$urlResolver->getHostKey()}");
        $this->newLine();
    }

    private function loginWithPersonalAccessToken(CredentialStore $credentials, FlareUrlResolver $urlResolver): int
    {
        if ($credentials->getRecord() !== null) {
            $this->warn('A browser-based OAuth session already exists for this host.');
            $this->line('Continuing will replace it with the personal access token below.');
            $this->newLine();
        }

        $tokenUrl = "{$urlResolver->getAppUrl()}/account/api-access";
        $this->line('Personal tokens are intended for automation and CI.');
        $this->line("You can generate one at <href={$tokenUrl}>{$tokenUrl}</>");
        $this->newLine();

        $token = $this->secret('Enter your Flare API token');

        if (! $token) {
            $this->error('No token provided.');

            return self::FAILURE;
        }

        try {
            // Without acceptJson, an invalid token gets redirected to the HTML
            // login page and reads as a successful response.
            $response = Http::withToken($token)->acceptJson()->get("{$urlResolver->getApiBaseUrl()}/me");
        } catch (ConnectionException) {
            $this->error('Could not connect to Flare. Please check your internet connection.');

            return self::FAILURE;
        }

        $email = $response->json('email');

        if (! $response->successful() || ! is_string($email) || $email === '') {
            $this->error('Invalid API token.');

            return self::FAILURE;
        }

        $credentials->setToken($token);

        return $this->reportSuccess($email, $urlResolver);
    }

    private function loginWithBrowser(
        CredentialStore $credentials,
        FlareUrlResolver $urlResolver,
        PkceLoginFlow $pkce,
        ?string $connectionName,
    ): int {
        $this->line('Opening your browser to log in. Sign in and approve the requested permissions.');
        $this->line('Use `<comment>flare login --token</comment>` for automation and CI.');
        $this->newLine();

        $opener = $this->makeBrowserOpener();
        $logger = fn (string $message) => $this->line($message);

        try {
            $record = $pkce->run(
                $opener,
                $logger,
                timeoutSeconds: (int) $this->option('timeout'),
                connectionName: $connectionName,
            );
        } catch (OAuthException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $credentials->setRecord($record);

        $email = $this->fetchEmail($record, $urlResolver);

        return $this->reportSuccess($email ?? 'unknown', $urlResolver);
    }

    private function fetchEmail(TokenRecord $record, FlareUrlResolver $urlResolver): ?string
    {
        try {
            $response = Http::withToken($record->accessToken)->acceptJson()->get("{$urlResolver->getApiBaseUrl()}/me");
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $email = $response->json('email');

        return is_string($email) ? $email : null;
    }

    private function reportSuccess(string $email, FlareUrlResolver $urlResolver): int
    {
        $this->newLine();
        $this->info("  🎉 Successfully logged in as {$email}  ");
        $this->line("Stored credentials for {$urlResolver->getHostKey()}.");
        $this->newLine();
        $this->line('The Flare CLI comes with a Claude Code skill that allows Claude to manage your errors and performance data.');
        $this->line('Publish it with: <comment>claude skill install spatie/flare-cli</comment>');
        $this->newLine();
        $this->line('Learn more: <href=https://flareapp.io/docs/flare/general/using-the-cli>https://flareapp.io/docs/flare/general/using-the-cli</>');

        return self::SUCCESS;
    }

    /**
     * @return callable(string): void
     */
    private function makeBrowserOpener(): callable
    {
        return PkceLoginFlow::defaultBrowserOpener(...);
    }

    private function loginWithDeviceCode(
        CredentialStore $credentials,
        FlareUrlResolver $urlResolver,
        DeviceLoginFlow $device,
        ?string $connectionName,
    ): int {
        $this->line('Starting device code authentication.');
        $this->newLine();

        $announce = function (DeviceAuthorization $auth): void {
            $verificationUrl = $auth->verificationUriComplete ?? $auth->verificationUri;

            $this->line('  Open this URL in any browser and confirm the code below:');
            $this->newLine();
            $this->line("    <href={$verificationUrl}>{$verificationUrl}</>");
            $this->newLine();
            $this->line("  User code: <comment>{$auth->userCode}</comment>");
            $this->newLine();
            $this->line('Waiting for confirmation...');
        };

        try {
            $record = $device->run($announce, connectionName: $connectionName);
        } catch (OAuthException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $credentials->setRecord($record);

        $email = $this->fetchEmail($record, $urlResolver);

        return $this->reportSuccess($email ?? 'unknown', $urlResolver);
    }

    private function connectionName(): ?string
    {
        $option = $this->option('name');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $hostname = gethostname();

        if (! is_string($hostname) || trim($hostname) === '') {
            return null;
        }

        return $hostname;
    }
}
