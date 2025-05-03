<?php

return [
    // Master switch to enable/disable the package
    'enabled' => env('VAAHSIGNOZ_ENABLED', true),

    // OpenTelemetry/SigNoz endpoint
    'endpoint' => env('VAAHSIGNOZ_ENDPOINT', 'http://localhost:4318/v1/traces'),

    // Service name for traces
    'service_name' => env('VAAHSIGNOZ_SERVICE_NAME', 'laravel-app'),

    // Which components to instrument
    'instrumentations' => [
        'cache'      => env('VAAHSIGNOZ_INSTRUMENT_CACHE', true),
        'client'     => env('VAAHSIGNOZ_INSTRUMENT_CLIENT', true),
        'log'        => env('VAAHSIGNOZ_INSTRUMENT_LOG', true),
        'exception'  => env('VAAHSIGNOZ_INSTRUMENT_EXCEPTION', true),
        'query'      => env('VAAHSIGNOZ_INSTRUMENT_QUERY', true),
    ],
];
