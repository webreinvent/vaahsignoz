<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    */
    'enabled' => env('VAAHSIGNOZ_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Configuration
    |--------------------------------------------------------------------------
    */
    'otel' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces'),
        'endpoint_logs' => env('OTEL_EXPORTER_LOGS_ENDPOINT', 'http://localhost:4318/v1/logs'),
        'service_name' => env('OTEL_SERVICE_NAME', 'laravel-app'),
        'version' => env('APP_VERSION') ?: null,
        'environment' => env('APP_ENV') ?: 'local',

        // Sampler config
        'sampler' => env('OTEL_SAMPLER', 'always_on'), // always_on | always_off | tracebased_per_million | parent_based
        'sampler_ratio' => (float) env('OTEL_SAMPLER_RATIO', 1.0),

        // Batch config
        'export_timeout' => (int) env('OTEL_EXPORT_TIMEOUT', 3000),     // ms
        'batch_max_size' => (int) env('OTEL_BATCH_MAX_SIZE', 512),
        'batch_timeout' => (int) env('OTEL_BATCH_TIMEOUT', 5000),      // ms

        // Certificate for TLS
        'certificate' => env('OTEL_EXPORTER_OTLP_CERTIFICATE'),

        // Shared HTTP client timeout (seconds)
        'http_timeout' => (float) env('VAAHSIGNOZ_HTTP_TIMEOUT', 3.0),
        'http_connect_timeout' => (float) env('VAAHSIGNOZ_CONNECT_TIMEOUT', 3.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Instrumentations
    |--------------------------------------------------------------------------
    */
    'instrumentations' => [
        'cache'     => env('VAAHSIGNOZ_INSTRUMENT_CACHE', true),
        'client'    => env('VAAHSIGNOZ_INSTRUMENT_CLIENT', true),
        'log'       => env('VAAHSIGNOZ_INSTRUMENT_LOG', true),
        'exception' => env('VAAHSIGNOZ_INSTRUMENT_EXCEPTION', true),
        'query'     => env('VAAHSIGNOZ_INSTRUMENT_QUERY', true),
        // Extended instrumentations
        'queue'     => env('VAAHSIGNOZ_INSTRUMENT_QUEUE', true),
        'event'     => env('VAAHSIGNOZ_INSTRUMENT_EVENT', false),
        'view'      => env('VAAHSIGNOZ_INSTRUMENT_VIEW', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled'   => env('VAAHSIGNOZ_METRICS_ENABLED', true),
        'http'      => env('VAAHSIGNOZ_METRICS_HTTP', true),
        'db'        => env('VAAHSIGNOZ_METRICS_DB', true),
        'cache'     => env('VAAHSIGNOZ_METRICS_CACHE', true),
        'exception' => env('VAAHSIGNOZ_METRICS_EXCEPTION', true),
        'process'   => env('VAAHSIGNOZ_METRICS_PROCESS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | N+1 Query Detection
    |--------------------------------------------------------------------------
    */
    'n_plus_one' => [
        'enabled'   => env('VAAHSIGNOZ_N_PLUS_ONE', true),
        'threshold' => (int) env('VAAHSIGNOZ_N_PLUS_ONE_THRESHOLD', 10), // alert after N queries
        'log'       => env('VAAHSIGNOZ_N_PLUS_ONE_LOG', true),
        'span'      => env('VAAHSIGNOZ_N_PLUS_ONE_SPAN', true),
        'metric'    => env('VAAHSIGNOZ_N_PLUS_ONE_METRIC', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Monitoring
    |--------------------------------------------------------------------------
    */
    'database' => [
        'capture_errors'       => env('VAAHSIGNOZ_DB_CAPTURE_ERRORS', true),
        'capture_transactions' => env('VAAHSIGNOZ_DB_CAPTURE_TRANSACTIONS', true),
        'capture_slow_queries' => env('VAAHSIGNOZ_DB_CAPTURE_SLOW', true),
        'slow_query_threshold_ms' => (float) env('VAAHSIGNOZ_DB_SLOW_THRESHOLD', 100),
        'monitor_connections'  => env('VAAHSIGNOZ_DB_MONITOR_CONNECTIONS', true),
        'error_log_level'      => env('VAAHSIGNOZ_DB_ERROR_LOG_LEVEL', 'critical'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'capture_php_errors'   => env('VAAHSIGNOZ_CAPTURE_PHP_ERRORS', true),
        'capture_fatal_errors' => env('VAAHSIGNOZ_CAPTURE_FATAL', true),
        'otlp_handler'         => env('VAAHSIGNOZ_OTLP_HANDLER', true),
        'log_levels'           => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */
    'security' => [
        'mask_keys' => [
            'password', 'token', 'api_token', 'api_key', 'secret',
            'authorization', 'credit_card', 'ssn', 'session', '_token',
            'password_confirmation',
        ],
        'max_request_body_size' => (int) env('VAAHSIGNOZ_MAX_REQUEST_BODY', 0), // 0 = disable capturing body
        'pii_mask' => env('VAAHSIGNOZ_PII_MASK', false), // hash user.email, user.name
    ],
];
