<?php

it('registers key API commands from the OpenAPI spec', function (string $command) {
    $commands = collect(\Illuminate\Support\Facades\Artisan::all())->keys()->toArray();

    expect($commands)->toContain($command);
})->with([
    'list-projects',
    'resolve-error',
    'list-error-occurrences',
    'get-authenticated-user',
    'create-project',
    'delete-project',
]);
