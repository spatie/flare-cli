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
            ->auth(fn () => app(CredentialStore::class)->getToken())
            ->banner(function ($command) {
                $lines = [
                    '  ███████╗██╗      █████╗ ██████╗ ███████╗',
                    '  ██╔════╝██║     ██╔══██╗██╔══██╗██╔════╝',
                    '  █████╗  ██║     ███████║██████╔╝█████╗  ',
                    '  ██╔══╝  ██║     ██╔══██║██╔══██╗██╔══╝  ',
                    '  ██║     ███████╗██║  ██║██║  ██║███████╗',
                    '  ╚═╝     ╚══════╝╚═╝  ╚═╝╚═╝  ╚═╝╚══════╝',
                ];

                $colors = [
                    '255;100;50',
                    '255;120;40',
                    '255;140;30',
                    '255;160;20',
                    '255;180;10',
                    '255;200;0',
                ];

                foreach ($lines as $i => $line) {
                    $command->line("\e[38;2;{$colors[$i]}m{$line}\e[0m");
                }

                $command->line('');
                $command->line('  ✦ Catch errors. Fix slowdowns. :: flareapp.io ✦');
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
