<?php

declare(strict_types=1);

return [

    /*
    | The base domain under which every tenant lives as a subdomain.
    | alnoor.gomal.test  -> subdomain "alnoor"
    */
    'base_domain' => env('TENANT_BASE_DOMAIN', 'gomal.test'),

    /*
    | Subdomains that can never be used by a tenant.
    */
    'reserved_subdomains' => ['www', 'api', 'hooks', 'admin', 'app', 'mail', 'static', 'cdn'],

    /*
    | D112, BR-T10: a dashboard password reset code follows the customer code rules (BR-CU07): 6 digits, valid
    | 10 minutes, 5 attempts, and at most 5 codes per email per hour.
    */
    'password_reset' => [
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'sends_per_hour' => 5,
    ],

];
