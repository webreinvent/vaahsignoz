<?php

return [
    'enabled' => env('SIGNOZ_ENABLED', false),
    'service_name' => env('SIGNOZ_SERVICE_NAME', 'laravel-app'),
    'endpoint' => env('SIGNOZ_ENDPOINT', 'http://localhost:4318/v1/traces'),
];
