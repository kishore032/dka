<?php

return [

    'rdka'          => env('rdka', 'dka.keyzero.org'),
    /*
    |--------------------------------------------------------------------------
    | DKA Identity
    |--------------------------------------------------------------------------
    */
    'username'      => env('DKA_USERNAME', 'dka'),
    'terse'         => env('DKA_TERSE', 'no-reply'),
    'domain'        => env('DKA_DOMAIN', 'dka.example.com'),
    'mail_domain' => env('DKA_MAIL_DOMAIN', '*'),
    'dns_canonical'    => env('DNS_CANONICAL'),

    /*
    |--------------------------------------------------------------------------
    | DKA Behavior
    |--------------------------------------------------------------------------
    */
    'token_ttl' => (int) env('DKA_TOKEN_TTL', 900),
    'version'   => (int) env('DKA_VERSION', 1),
    'DKIM_required' => env('DKIM_CHECK_REQUIRED'),

    /*
    |--------------------------------------------------------------------------
    | Mailgun
    |--------------------------------------------------------------------------
    */
    'mg_signing_key' => env('MG_SIGNING_KEY'),
    'mg_domain'      => env('MG_DOMAIN'),
    'mg_secret'      => env('MG_SECRET'),
];
