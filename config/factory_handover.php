<?php
return [
    // Enable only after the central migration/routes and Android retry client are deployed.
    'enabled' => env('FACTORY_VPN_HANDOVER_ENABLED', false),
    'base_url' => env('FACTORY_VPN_SUBSCRIPTION_API_URL', 'https://stellersubscriptionapiprod.azurewebsites.net/api/v1/'),
    'username' => env('APPSETTING_API_USERNAME_STELLER_SUBSCRIPTION_API'),
    'password' => env('APPSETTING_API_PASSWORD_STELLER_SUBSCRIPTION_API'),
];
