<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORRAD → KAIS (Laravel) single sign-on
    |--------------------------------------------------------------------------
    |
    | Shared secret must match CORRAD / MYFIS PHP (see docs/corrad-kais-integration.md).
    | When empty, /auth/corrad-sso is disabled (404).
    |
    */

    'sso_secret' => env('CORRAD_SSO_SECRET'),

    'sso_max_age_seconds' => (int) env('CORRAD_SSO_MAX_AGE_SECONDS', 120),

];
