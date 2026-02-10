<?php

it('displays the tagline in flare:list output', function () {
    $this->artisan('flare:list')
        ->expectsOutputToContain('flareapp.io')
        ->assertExitCode(0);
});
