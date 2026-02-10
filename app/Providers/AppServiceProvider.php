<?php

namespace App\Providers;

use App\Concerns\RendersBanner;
use App\Services\CredentialStore;
use App\Services\FlareDescriber;
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

        OpenApiCli::register(
            specPath: resource_path('openapi/flare-api.yaml'),
            prefix: 'flare',
        )
            ->useOperationIds()
            ->auth(fn () => app(CredentialStore::class)->getToken())
            ->banner(function ($command) {
                $renderer = new class
                {
                    use RendersBanner;
                };

                $renderer->renderBanner($command->getOutput());
            });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CredentialStore::class);
    }
}
