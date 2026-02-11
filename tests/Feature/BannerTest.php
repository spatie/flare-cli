<?php

it('displays the tagline in default output', function () {
    $this->artisan('list')
        ->expectsOutputToContain('flareapp.io')
        ->assertExitCode(0);
});
