<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transaction Logging
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for long-running database transactions. When
    | enabled, the package will listen to transaction lifecycle events and log
    | the outer-most transaction that exceeds the configured duration.
    |
    */
    'log_transactions' => [
        // Toggle transaction logging without removing the event listeners.
        'enabled' => env('MYSQL_DEADLOCK_RETRY_TRANSACTION_LOG_ENABLED', false),

        // Restrict logging to specific environments. Empty array == all envs.
        'environments' => ['production'],

        // Minimum transaction duration (ms) required before logging occurs.
        'min_transaction_ms' => env('MYSQL_DEADLOCK_RETRY_MIN_TRANSACTION_MS', 2000),

        // Minimum query duration (ms) to include in the logged payload.
        'min_query_ms' => env('MYSQL_DEADLOCK_RETRY_MIN_QUERY_MS', 1000),

        // Optional log channel to target; null uses the default channel.
        'log_channel' => env('MYSQL_DEADLOCK_RETRY_LOG_CHANNEL'),

        // Customize the log level for commits and rollbacks individually.
        'commit_log_level' => env('MYSQL_DEADLOCK_RETRY_COMMIT_LOG_LEVEL', 'warning'),
        'rollback_log_level' => env('MYSQL_DEADLOCK_RETRY_ROLLBACK_LOG_LEVEL', 'error'),
    ],
];
