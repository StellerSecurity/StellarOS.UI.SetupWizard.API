<?php
return [
    // Reuse the same upstream and credential variable names as SubscriptionService.
    'base_url' => rtrim(env('STELLAR_SUBSCRIPTION_BASE_URL', 'https://stellersubscriptionapiprod.azurewebsites.net/api/'), '/') . '/v1/',
    'username' => env(env('STELLAR_SUBSCRIPTION_USERNAME_ENV', 'APPSETTING_API_USERNAME_STELLER_SUBSCRIPTION_API')),
    'password' => env(env('STELLAR_SUBSCRIPTION_PASSWORD_ENV', 'APPSETTING_API_PASSWORD_STELLER_SUBSCRIPTION_API')),
];
