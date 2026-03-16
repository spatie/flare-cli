<?php

namespace App\Providers;

use App\Commands\HelpCommand;
use App\Services\CredentialStore;
use App\Services\FlareDescriber;
use App\Services\FlareUrlResolver;
use Illuminate\Console\Application as Artisan;
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

        Artisan::starting(function (Artisan $artisan) {
            $artisan->addCommand(new HelpCommand);
        });

        OpenApiCli::register(specPath: 'https://flareapp.io/downloads/flare-api.yaml')
            ->useOperationIds()
            ->baseUrl($urlResolver->getApiBaseUrl())
            ->cache(ttl: 60 * 60 * 24)
            ->auth(fn () => app(CredentialStore::class)->getToken())
            ->onError(function (Response $response, Command $command) {
                if ($response->status() === 401) {
                    $command->error(
                        'Your API token is invalid or expired. Run `flare login` to authenticate.',
                    );

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
        $this->app->singleton(CredentialStore::class);
    }
}
