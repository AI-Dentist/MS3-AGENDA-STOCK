<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Enables communication with Angular Shell (http://localhost:4200)
    | and the unified API Gateway.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:4200',
        'http://127.0.0.1:4200',
        env('API_GATEWAY_URL', 'http://localhost:8080'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*',
        'Idempotency-Key',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-User-Id',
        'X-User-Roles',
    ],

    'exposed_headers' => ['Idempotency-Key'],

    'max_age' => 0,

    'supports_credentials' => true,

];
