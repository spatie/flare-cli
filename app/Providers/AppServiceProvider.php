<?php

namespace App\Providers;

use App\Services\CredentialStore;
use Illuminate\Support\ServiceProvider;
use Spatie\OpenApiCli\OpenApiCli;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        OpenApiCli::register(
            specPath: resource_path('openapi/flare-api.yaml'),
            prefix: 'flare',
        )
            ->useOperationIds()
            ->auth(fn () => app(CredentialStore::class)->getToken());
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CredentialStore::class);
    }
}
