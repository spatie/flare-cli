<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth client
    |--------------------------------------------------------------------------
    |
    | Flare's first-party CLI client is a public Passport client (no secret).
    | The client_id below is the development seed value. Replace with the
    | production UUID before tagging a release.
    |
    | Override per-environment with the FLARE_OAUTH_CLIENT_ID env var.
    */

    'oauth' => [
        'client_id' => env('FLARE_OAUTH_CLIENT_ID', '9d000000-0000-4000-8000-000000000001'),

        /*
         * The CLI requests `admin` so that project/team-management commands
         * (create-project, delete-project, remove-team-user) work after login.
         * The Flare consent screen always lets the user narrow this at grant time.
         */
        'scopes' => ['read', 'write', 'admin'],
        'refresh_threshold_seconds' => 60,
    ],
];
