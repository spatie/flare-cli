<?php

namespace App\Providers;

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
                $lines = [
                    '  ███████╗ ██╗       █████╗  ██████╗  ███████╗',
                    '  ██╔════╝ ██║      ██╔══██╗ ██╔══██╗ ██╔════╝',
                    '  █████╗   ██║      ███████║ ██████╔╝ █████╗  ',
                    '  ██╔══╝   ██║      ██╔══██║ ██╔══██╗ ██╔══╝  ',
                    '  ██║      ███████╗ ██║  ██║ ██║  ██║ ███████╗',
                    '  ╚═╝      ╚══════╝ ╚═╝  ╚═╝ ╚═╝  ╚═╝ ╚══════╝',
                ];

                $gradient = [49, 43, 37, 99, 135, 93];

                $command->line('');

                foreach ($lines as $i => $line) {
                    $command->line("\e[38;5;{$gradient[$i]}m{$line}\e[0m");
                }

                $command->line('');

                $tagline = ' ✦ Catch errors. Fix slowdowns. :: flareapp.io ✦ ';
                $command->line("\e[48;5;{$gradient[0]}m\e[30m\e[1m{$tagline}\e[0m");

                $command->line('');
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
