<?php

return [

    // Discord webhook for all alerts. Empty: alerts are only logged.
    'discord_webhook' => env('SITE_ALERTS_DISCORD_WEBHOOK'),

    'heartbeat' => [
        // Ping URL of the Healthchecks.io check. Empty: no heartbeat.
        'url' => env('SITE_ALERTS_HEARTBEAT_URL'),

        // Comma-separated queues the workers process. The heartbeat passes through each of them.
        'queues' => env('SITE_ALERTS_HEARTBEAT_QUEUES', 'default'),
    ],

    'failed_jobs' => [
        // Reminder while failed_jobs is not empty: daily, weekly or off.
        'reminder' => env('SITE_ALERTS_FAILED_JOBS_REMINDER', 'weekly'),

        // Failed jobs older than this many days are pruned. 0: keep forever.
        'retention_days' => (int) env('SITE_ALERTS_FAILED_JOBS_RETENTION_DAYS', 30),
    ],

    // Report unexpected errors in web requests.
    'exceptions' => (bool) env('SITE_ALERTS_EXCEPTIONS', true),

];
