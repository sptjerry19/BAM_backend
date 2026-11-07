<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature flags (fail closed: everything is off by default)
    |--------------------------------------------------------------------------
    */
    'enabled' => (bool) env('CONTENT_STUDIO_ENABLED', false),
    'autopublish_enabled' => (bool) env('CONTENT_AUTOPUBLISH_ENABLED', false),

    // Emergency kill switch from the environment. The admin API can also pause
    // automation at runtime (content_settings.automation_paused).
    'automation_paused' => (bool) env('CONTENT_AUTOMATION_PAUSED', false),

    /*
    |--------------------------------------------------------------------------
    | Scheduler scope
    |--------------------------------------------------------------------------
    | all     - social:* and content:* (default, keeps existing behaviour)
    | content - only content:* (used by the content-scheduler container so it
    |           does not switch on the social:* schedule that is currently off)
    | social  - only social:*
    */
    'schedule_scope' => env('CONTENT_SCHEDULE_SCOPE', 'all'),

    'timezone' => 'Asia/Ho_Chi_Minh',

    'channel' => [
        'platform' => 'tiktok',
        'handle' => env('CONTENT_CHANNEL_HANDLE', 'jerrybadminton'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | `media` uses its own connection (config/queue.php) with a longer
    | retry_after so that long video jobs are not handed out twice.
    */
    'queues' => [
        'connection' => 'redis',
        'default' => 'content',
        'media_connection' => 'redis-media',
        'media' => 'content-media',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline steps
    |--------------------------------------------------------------------------
    */
    'steps' => [
        'max_attempts' => 3,
        'lease_seconds' => 300,
        // Added on top of a job timeout when computing a lease.
        'lease_grace_seconds' => 60,
    ],

    'recover' => [
        // An unfinished run that has been idle this long gets its next job re-sent.
        'stale_after_minutes' => 10,

        // Disks a step may name in input_json.expected_output (see StepOutputProbe).
        'probe_disks' => ['s3', 'local', 'public'],
    ],
];
