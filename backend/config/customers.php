<?php

declare(strict_types=1);

return [

    /*
    | BR-CU07: OTP rules. 6 digits, valid 10 minutes, 5 verification attempts, 5 sends per phone per hour,
    | and "send by SMS instead" only 60 seconds after the previous code.
    */
    'otp' => [
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'sends_per_hour' => 5,
        'sms_after_seconds' => 60,
    ],

    /*
    | BR-CU08: the invitation link is valid 7 days. `{host}` is the tenant host and `{token}` the one-time
    | token; the storefront page at that path lets the customer set a password.
    */
    'invitation' => [
        'ttl_days' => 7,
        'url' => env('CUSTOMER_INVITATION_URL', 'https://{host}/invite/{token}'),
    ],

    /*
    | D50: customer passwords are at least 8 characters, no complexity rules.
    */
    'password_min' => 8,

];
