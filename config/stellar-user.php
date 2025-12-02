<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stellar User API base URL
    |--------------------------------------------------------------------------
    |
    | This is the base URL for the Stellar User API. You normally do not need
    | to change this in production. For local testing you can override it
    | with STELLAR_USER_BASE_URL in your .env file.
    |
    */

    'base_url' => env('STELLAR_USER_BASE_URL', 'https://stellaruserapiprod.azurewebsites.net/api/'),

    /*
    |--------------------------------------------------------------------------
    | Basic auth credentials
    |--------------------------------------------------------------------------
    |
    | These environment variables hold the username and password for the
    | Stellar User API. They are injected into the underlying HTTP client
    | using basic authentication on every request.
    |
    */

    'username_env_key' => env('STELLAR_USER_USERNAME_KEY', 'APPSETTING_API_USERNAME_STELLAR_USER_API'),
    'password_env_key' => env('STELLAR_USER_PASSWORD_KEY', 'APPSETTING_API_PASSWORD_STELLAR_USER_API'),

];
