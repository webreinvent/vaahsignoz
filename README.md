# VaahSignoz
Laravel package to integrate SigNoz with laravel applications


## Installation

- Require the package via Composer:
```shell
composer require webreinvent/vaahsignoz
```
- Publish the configuration:

```shell
php artisan vendor:publish --provider="WebReinvent\VaahSignoz\VaahSignozServiceProvider" --tag="config"
```

- Set environment variables in your `.env` file:
```dotenv
SIGNOZ_ENDPOINT=http://localhost:4317
APP_VERSION=1.0.0
```

## Configuration
The config file `config/vaahsignoz.php`:

```php
return [
    'endpoint' => env('SIGNOZ_ENDPOINT', 'http://localhost:4317'),
    'service_name' => env('APP_NAME', 'laravel-app'),
    'app_version' => env('APP_VERSION', '1.0.0'),
];
```

## Usage

### Automatic Tracing
Once installed, the package will automatically instrument your Laravel app and send traces to SigNoz.

#### Middleware
For manual tracing, you can use the included middleware. Add to your `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \WebReinvent\VaahSignoz\SigNozTraceMiddleware::class,
];
```

#### How it Works?
- On boot, the package initializes the OpenTelemetry tracer with your service name and version.
- All incoming HTTP requests, database queries, and exceptions are traced and exported to SigNoz.
- You can extend or customize tracing by editing OpenTelemetryService.php or the middleware.

### Manual Span

```php
use WebReinvent\VaahSignoz\Services\OpenTelemetryService;

$tracer = OpenTelemetryService::getTracer();
$span = $tracer->spanBuilder('custom-operation')->startSpan();
$span->end();
```
