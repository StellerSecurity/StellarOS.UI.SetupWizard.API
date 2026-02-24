<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stellar Subscription API base URL
    |--------------------------------------------------------------------------
    |
    | This is the base URL for the external Stellar Subscription API.
    | You normally do not need to change this value.
    |
    */

    'base_url' => env('STELLAR_SUBSCRIPTION_BASE_URL', 'https://stellersubscriptionapiprod.azurewebsites.net/api/'),

    /*
    |--------------------------------------------------------------------------
    | Basic Auth credentials
    |--------------------------------------------------------------------------
    |
    | The package will read the username and password from environment
    | variables. In Azure App Service, these are typically stored as
    | application settings.
    |
    */

    'username_env' => env('STELLAR_SUBSCRIPTION_USERNAME_ENV', 'APPSETTING_API_USERNAME_STELLER_SUBSCRIPTION_API'),
    'password_env' => env('STELLAR_SUBSCRIPTION_PASSWORD_ENV', 'APPSETTING_API_PASSWORD_STELLER_SUBSCRIPTION_API'),

];
