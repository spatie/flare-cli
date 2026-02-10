<?php

it('registers key API commands from the OpenAPI spec', function (string $command) {
    $commands = collect(\Illuminate\Support\Facades\Artisan::all())->keys()->toArray();

    expect($commands)->toContain($command);
})->with([
    'flare:list-projects',
    'flare:resolve-error',
    'flare:list-error-occurrences',
    'flare:get-authenticated-user',
    'flare:create-project',
    'flare:delete-project',
    'flare:list',
]);
