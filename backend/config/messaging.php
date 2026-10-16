<?php

declare(strict_types=1);

return [

    /*
    | Which driver sends OTP and invitation messages (Tech §13, D11).
    |   hypersender  WhatsApp + SMS through hypersender.com (production)
    |   log          writes every message to storage/logs/messages.log (development, tests)
    */
    'driver' => env('MESSAGING_DRIVER', 'log'),

    'hypersender' => [
        'base_url' => env('HYPERSENDER_BASE_URL', 'https://app.hypersender.com/api'),
        'token' => env('HYPERSENDER_TOKEN', ''),
        'sms_token' => env('HYPERSENDER_SMS_TOKEN', env('HYPERSENDER_TOKEN', '')),
        'whatsapp_instance' => env('HYPERSENDER_WHATSAPP_INSTANCE', ''),
        'sms_instance' => env('HYPERSENDER_SMS_INSTANCE', ''),

        // send-text-safe marks the chat read and simulates typing before sending (ban-risk mitigation, D46).
        'safe_send' => (bool) env('HYPERSENDER_SAFE_SEND', true),

        'timeout' => 10,
    ],

    /*
    | The store's own WhatsApp number (D70, tasks/12): wa-gateway on the same machine holds one WhatsApp Web
    | session per linked store. Disabled, every message uses the platform driver above and the dashboard
    | hides the section.
    */
    'wa_gateway' => [
        'enabled' => (bool) env('WA_GATEWAY_ENABLED', false),
        'base_url' => env('WA_GATEWAY_URL', 'http://127.0.0.1:3100'),
        'token' => env('WA_GATEWAY_TOKEN', ''),
        // wa-gateway signs every webhook with HMAC-SHA256 under this secret. Empty means every webhook is refused.
        'webhook_secret' => env('WA_GATEWAY_WEBHOOK_SECRET', ''),
        'timeout' => 10,
    ],

    /*
    | Hypersender sends this value as the `Authorization` header of every webhook call (no HMAC exists).
    | Empty means every webhook is refused.
    */
    'webhook_secret' => env('MESSAGING_WEBHOOK_SECRET', ''),

    'limits' => [
        'per_phone_per_hour' => 5,   // BR-CU07
        'provider_per_minute' => 10, // Hypersender plan limit and D46 ban-risk mitigation
    ],

    'poll' => [
        // Seconds before each re-check of a queued provider request; its length is the maximum number of polls.
        'delays' => [5, 10, 20, 40, 60],
    ],

];
