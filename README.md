# vaahsignoz

**Automatic OpenTelemetry instrumentation for Laravel applications with SigNoz integration.**

## Features

- Enable/disable tracing via config
- Per-feature instrumentation toggle: cache, client, exceptions, log, query
- All OpenTelemetry endpoint/service info via configuration
- Detailed Laravel events integration
- Facade for easy access/control
- Extensible, robust, and follows Laravel package best practices

## Installation

```bash
composer require webreinvent/vaahsignoz
```

Publish the config:

```bash
php artisan vendor:publish --provider="Webreinvent\VaahSignoz\VaahSignozServiceProvider" --tag="config"
```
## Configuration

Edit `config/vaahsignoz.php`

```php
return [
    'enabled' => env('VAAHSIGNOZ_ENABLED', true),
    'endpoint' => env('VAAHSIGNOZ_ENDPOINT', 'http://localhost:4318/v1/traces'),
    'service_name' => env('VAAHSIGNOZ_SERVICE_NAME', 'laravel-app'),
    'instrumentations' => [
        'cache'      => env('VAAHSIGNOZ_INSTRUMENT_CACHE', true),
        'client'     => env('VAAHSIGNOZ_INSTRUMENT_CLIENT', true),
        'log'        => env('VAAHSIGNOZ_INSTRUMENT_LOG', true),
        'exception'  => env('VAAHSIGNOZ_INSTRUMENT_EXCEPTION', true),
        'query'      => env('VAAHSIGNOZ_INSTRUMENT_QUERY', true),
    ],
];
```

## Usage

```php
use VaahSignoz;

// Instrument a specific service
VaahSignoz::instrument('log');

// Get config
$config = VaahSignoz::getConfig();
```

### HTTP Request Tracing

To capture every HTTP request as a trace, register the VaahSignoz middleware in your Laravel application.

**Register globally in `app/Http/Kernel.php`:**

```php
protected $middleware = [
    // ...
    \WebReinvent\VaahSignoz\src\Middleware\RequestInstrumentation::class,
];
```
## Advanced Usage

### Add Custom Instrumentation

You can register your own event listeners or instrumentation hooks:

```php
use VaahSignoz;

VaahSignoz::registerInstrumentation(function () {
    // For example, listen to custom Laravel events
    \Illuminate\Support\Facades\Event::listen('my.special.event', function ($event) {
        $tracer = \WebReinvent\VaahSignoz\src\Tracer\TracerFactory::getTracer();
        $span = $tracer->spanBuilder('my.custom.event')->startSpan();
        $span->setAttribute('custom.event.data', json_encode($event));
        $span->end();
    });
});

## Error Handling

Any unexpected error during instrumentation will throw 

```php
Webreinvent\VaahSignoz\Exceptions\VaahSignozException
```

## Extending

Add your own instrumentation by creating a class under
```
src/Instrumentation/
```

## User-defined Instrumentation Example

```php
VaahSignoz::registerInstrumentation(function () {
    Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
        $tracer = \WebReinvent\VaahSignoz\src\Tracer\TracerFactory::getTracer();
        $span = $tracer->spanBuilder('user.login')->startSpan();
        $span->setAttribute('user.id', $event->user->id);
        $span->end();
    });
});
```

## License

The MIT License (MIT).

