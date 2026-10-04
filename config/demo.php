<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Show demo credentials on the login page
    |--------------------------------------------------------------------------
    |
    | When true, the login page renders the full seeded user list (role,
    | abilities, email, password = "password") so visitors to a demo
    | deployment can sign in as any persona.
    |
    | ALWAYS leave this off for a real production tenant. The licentra
    | demo deployment at licentra.charsleydigital.co.za ships with it on;
    | real licensing-company deployments do not.
    |
    */

    'show_credentials' => filter_var(
        env('DEMO_MODE_CREDENTIALS', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
