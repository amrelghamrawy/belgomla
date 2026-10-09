<?php

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

];
