<?php

return [
    // Master switch to enable/disable the package
    'enabled' => env('VAAHSIGNOZ_ENABLED', true),

    // OpenTelemetry config (now via env vars!)
    // You can use these in TracerFactory with `putenv()` or just document them.
    'otel' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces'),
        'endpoint_logs' => env('OTEL_EXPORTER_LOGS_ENDPOINT', 'http://localhost:4318/v1/logs'),
        'service_name' => env('OTEL_SERVICE_NAME', 'laravel-app'),
        'version' => env('APP_VERSION') ?: null,
        'environment' => env('APP_ENV') ?: 'local',
    ],

    // Which features to instrument
    'instrumentations' => [
        'cache'      => env('VAAHSIGNOZ_INSTRUMENT_CACHE', true),
        'client'     => env('VAAHSIGNOZ_INSTRUMENT_CLIENT', true),
        'log'        => env('VAAHSIGNOZ_INSTRUMENT_LOG', true),
        'exception'  => env('VAAHSIGNOZ_INSTRUMENT_EXCEPTION', true),
        'query'      => env('VAAHSIGNOZ_INSTRUMENT_QUERY', true),
    ],
];
