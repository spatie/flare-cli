<?php

namespace App\Providers;

use App\Services\CredentialStore;
use App\Services\FlareDescriber;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\DeviceLoginFlow;
use App\Services\OAuth\OAuthDiscovery;
use App\Services\OAuth\OAuthEndpoints;
use App\Services\OAuth\OAuthHttpClient;
use App\Services\OAuth\OAuthRevoker;
use App\Services\OAuth\PkceLoginFlow;
use App\Services\OAuth\TokenRefresher;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\ServiceProvider;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;
use Spatie\OpenApiCli\OpenApiCli;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(DescriberContract::class, FlareDescriber::class);
        $urlResolver = $this->app->make(FlareUrlResolver::class);

        OpenApiCli::register(specPath: 'https://flareapp.io/downloads/flare-api.yaml')
            ->useOperationIds()
            ->baseUrl($urlResolver->getApiBaseUrl())
            ->cache(ttl: 60 * 60 * 24)
            ->auth(fn () => app(CredentialStore::class)->getAccessToken())
            ->retryOn(function (Response $response) {
                if ($response->status() !== 401) {
                    return false;
                }

                return app(CredentialStore::class)->forceRefresh();
            })
            ->onError(function (Response $response, Command $command) use ($urlResolver) {
                $status = $response->status();

                if (! in_array($status, [401, 403], true)) {
                    return false;
                }

                $message = $response->json('message');

                if ($message === 'Token has no resource authorization.') {
                    $command->error('This Flare connection has been revoked.');
                    $command->line('Run `flare login` to create a new connection.');

                    return true;
                }

                if ($message === 'Token was issued for a different resource.') {
                    $command->error('These credentials were issued for a different Flare resource.');
                    $command->line('Run `flare login` for the active API profile.');

                    return true;
                }

                if ($status === 401) {
                    $command->error('Your Flare credentials are invalid or expired.');
                    $command->line('Run `flare login` to authenticate again.');

                    return true;
                }

                if (
                    is_string($message)
                    && (
                        str_starts_with($message, 'Token ')
                        || str_starts_with($message, 'Connection ')
                    )
                ) {
                    $command->error($message);
                    $command->line("Manage this connection at {$urlResolver->getAppUrl()}/account/api-access");

                    return true;
                }

                return false;
            });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FlareUrlResolver::class);
        $this->app->singleton(OAuthDiscovery::class);
        $this->app->singleton(OAuthEndpoints::class);
        $this->app->singleton(CredentialStore::class);
        $this->app->singleton(OAuthRevoker::class);

        $this->app->singleton(OAuthHttpClient::class, fn ($app) => new OAuthHttpClient(
            $app->make(OAuthEndpoints::class),
            (string) config('flare.oauth.client_id'),
        ));

        $this->app->singleton(TokenRefresher::class, fn ($app) => new TokenRefresher(
            $app->make(OAuthHttpClient::class),
            (int) config('flare.oauth.refresh_threshold_seconds', 60),
        ));

        $this->app->bind(PkceLoginFlow::class, fn ($app) => new PkceLoginFlow(
            $app->make(OAuthHttpClient::class),
            $app->make(OAuthEndpoints::class),
            (string) config('flare.oauth.client_id'),
            (array) config('flare.oauth.scopes', ['read', 'write', 'admin']),
        ));

        $this->app->bind(DeviceLoginFlow::class, fn ($app) => new DeviceLoginFlow(
            $app->make(OAuthHttpClient::class),
            (array) config('flare.oauth.scopes', ['read', 'write', 'admin']),
        ));
    }
}
