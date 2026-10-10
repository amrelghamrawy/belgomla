<?php

declare(strict_types=1);

/*
| Production monitoring (tasks/11-release.md, D65). `platform:monitor` runs every 5 minutes from
| deploy/monitor.sh and alerts the platform operator over WhatsApp, sent through Messenger as the
| `alert_tenant` (Messenger needs a tenant in context). Without both alert values it only logs.
*/

return [
    'alert_tenant' => env('MONITOR_ALERT_TENANT'),
    'alert_phone' => env('MONITOR_ALERT_PHONE'),

    // The same open problem is re-sent at most this often (BR-CU07 allows 5 messages per phone per hour).
    'realert_minutes' => (int) env('MONITOR_REALERT_MINUTES', 60),

    'queue_backlog_max' => (int) env('MONITOR_QUEUE_BACKLOG_MAX', 50),

    'disk_path' => env('MONITOR_DISK_PATH', base_path()),
    'disk_min_free_percent' => (int) env('MONITOR_DISK_MIN_FREE_PERCENT', 10),

    // D49: the SMS phone counts as offline after 2 hours without a heartbeat. Checked only with the hypersender driver.
    'heartbeat_max_minutes' => (int) env('MONITOR_HEARTBEAT_MAX_MINUTES', 120),
];
