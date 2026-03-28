<?php

namespace App\Helpers;

class AccountNumberHelper
{

    public static $keyEmail = "1234randomized9877";

    // never change this
    public static string $salt = "key_1105_54izuAs0qa";

    public static function displayAccountNumberOrEmail(string $subscription_id, string $email)
    {

        // the users vpn account is an account that is created without email, (account number exists only).
        if (str_contains($email, self::$keyEmail)) {
            return $subscription_id;
        }

        return $email;

    }

}
