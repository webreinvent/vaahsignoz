# VaahSignoz

**Automatic OpenTelemetry instrumentation for Laravel applications with SigNoz integration.**

Collects **traces**, **metrics**, and **logs** from your Laravel application and sends them to SigNoz (or any OpenTelemetry-compatible backend) via the OTLP protocol.

---

## Features

| Signal | What's Captured |
|--------|----------------|
| **Traces** | HTTP requests, DB queries, cache operations, HTTP client calls, queue jobs, Laravel events, view rendering |
| **Metrics** | Request rate, latency (p50/p95/p99), DB query duration, cache hit/miss ratio, error count, memory usage |
| **Logs** | All 8 log levels, PHP warnings/notices/deprecations, fatal errors, with trace correlation (`trace_id`/`span_id`) |
| **Database** | Query tracing, transaction tracking (begin/commit/rollback), error classification (deadlock, lock timeout, connection lost), N+1 detection |

---

## Installation

```bash
composer require webreinvent/vaahsignoz
```

---

## Quick Start (5 Steps)

### Step 1: Publish Configuration

```bash
php artisan vendor:publish --provider="WebReinvent\VaahSignoz\VaahSignozServiceProvider" --tag="config"
```

This creates `config/vaahsignoz.php`.

### Step 2: Configure OTLP Endpoint

Set your SigNoz (or OTLP backend) endpoint in `.env`:

```env
# OTLP endpoint (traces + metrics)
OTEL_EXPORTER_OTLP_ENDPOINT=http://your-signoz-host:4318/v1/traces

# Logs endpoint (optional, defaults to same host /v1/logs)
OTEL_EXPORTER_LOGS_ENDPOINT=http://your-signoz-host:4318/v1/logs

# Service name (how your app appears in SigNoz)
OTEL_SERVICE_NAME=my-laravel-app
```

### Step 3: Register the Service Provider

Laravel 11+ (`bootstrap/providers.php`):

```php
use WebReinvent\VaahSignoz\VaahSignozServiceProvider;

return Providers::default()->merge([
    // ...
    VaahSignozServiceProvider::class,
])->toArray();
```

Laravel 8-10 (`config/app.php`):

```php
'providers' => ServiceProvider::defaultProviders()->merge([
    // ...
    \WebReinvent\VaahSignoz\VaahSignozServiceProvider::class,
])->toArray(),
```

> **Note:** The service provider is auto-discovered in most cases via the `extra.laravel.providers` key in `composer.json`. Manual registration is only needed if you disabled auto-discovery.

### Step 4: Register the HTTP Middleware

To trace every HTTP request, add the middleware to your application.

**Laravel 11+** (`app/Http/Kernel.php`):

```php
protected $middleware = [
    // ...
    \WebReinvent\VaahSignoz\Middleware\RequestInstrumentation::class,
];
```

**Laravel 8-10** (`app/Http/Kernel.php`):

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \WebReinvent\VaahSignoz\Middleware\RequestInstrumentation::class,
    ],
    'api' => [
        // ...
        \WebReinvent\VaahSignoz\Middleware\RequestInstrumentation::class,
    ],
];
```

### Step 5: Verify

Make a request to your application and check SigNoz:

1. Open your SigNoz dashboard (e.g., `http://your-signoz-host:3301`)
2. Navigate to **Services** → select your service name
3. You should see traces, metrics, and logs flowing in

---

## Configuration

Edit `config/vaahsignoz.php` or use environment variables:

### Core Settings

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `enabled` | `VAAHSIGNOZ_ENABLED` | `true` | Master on/off switch |
| `otel.endpoint` | `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318/v1/traces` | OTLP endpoint for traces + metrics |
| `otel.endpoint_logs` | `OTEL_EXPORTER_LOGS_ENDPOINT` | `http://localhost:4318/v1/logs` | OTLP endpoint for logs |
| `otel.service_name` | `OTEL_SERVICE_NAME` | `laravel-app` | Service name in SigNoz |
| `otel.version` | `APP_VERSION` | `null` | Application version |
| `otel.environment` | `APP_ENV` | `local` | Deployment environment |

### Sampling

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `otel.sampler` | `OTEL_SAMPLER` | `always_on` | Sampler: `always_on`, `always_off`, `tracebased_per_million`, `parent_based` |
| `otel.sampler_ratio` | `OTEL_SAMPLER_RATIO` | `1.0` | Sampling ratio (0.0 - 1.0) for `tracebased_per_million` |

### Instrumentation Toggles

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `instrumentations.cache` | `VAAHSIGNOZ_INSTRUMENT_CACHE` | `true` | Cache hit/miss/write/forget spans + metrics |
| `instrumentations.client` | `VAAHSIGNOZ_INSTRUMENT_CLIENT` | `true` | HTTP outgoing request spans |
| `instrumentations.log` | `VAAHSIGNOZ_INSTRUMENT_LOG` | `true` | Application log → SigNoz |
| `instrumentations.exception` | `VAAHSIGNOZ_INSTRUMENT_EXCEPTION` | `true` | Exception spans + logs |
| `instrumentations.query` | `VAAHSIGNOZ_INSTRUMENT_QUERY` | `true` | DB query spans + metrics |
| `instrumentations.queue` | `VAAHSIGNOZ_INSTRUMENT_QUEUE` | `true` | Queue job spans + metrics |
| `instrumentations.event` | `VAAHSIGNOZ_INSTRUMENT_EVENT` | `false` | Laravel event dispatch spans |
| `instrumentations.view` | `VAAHSIGNOZ_INSTRUMENT_VIEW` | `false` | View rendering spans |

### Metrics

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `metrics.enabled` | `VAAHSIGNOZ_METRICS_ENABLED` | `true` | Master metrics switch |
| `metrics.http` | `VAAHSIGNOZ_METRICS_HTTP` | `true` | HTTP request metrics |
| `metrics.db` | `VAAHSIGNOZ_METRICS_DB` | `true` | DB query metrics |
| `metrics.cache` | `VAAHSIGNOZ_METRICS_CACHE` | `true` | Cache metrics |
| `metrics.exception` | `VAAHSIGNOZ_METRICS_EXCEPTION` | `true` | Exception metrics |
| `metrics.process` | `VAAHSIGNOZ_METRICS_PROCESS` | `true` | Memory/peak memory gauges |

### N+1 Query Detection

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `n_plus_one.enabled` | `VAAHSIGNOZ_N_PLUS_ONE` | `true` | Enable N+1 detection |
| `n_plus_one.threshold` | `VAAHSIGNOZ_N_PLUS_ONE_THRESHOLD` | `10` | Alert after N queries |
| `n_plus_one.log` | `VAAHSIGNOZ_N_PLUS_ONE_LOG` | `true` | Emit warning log |
| `n_plus_one.span` | `VAAHSIGNOZ_N_PLUS_ONE_SPAN` | `true` | Create `db.n_plus_one` span |
| `n_plus_one.metric` | `VAAHSIGNOZ_N_PLUS_ONE_METRIC` | `true` | Increment counter |

### Database Monitoring

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `database.capture_errors` | `VAAHSIGNOZ_DB_CAPTURE_ERRORS` | `true` | Capture PDO exceptions, deadlocks, connection errors |
| `database.capture_transactions` | `VAAHSIGNOZ_DB_CAPTURE_TRANSACTIONS` | `true` | Track begin/commit/rollback |
| `database.capture_slow_queries` | `VAAHSIGNOZ_DB_CAPTURE_SLOW` | `true` | Flag slow queries (Laravel 9+) |
| `database.slow_query_threshold_ms` | `VAAHSIGNOZ_DB_SLOW_THRESHOLD` | `100` | Slow query threshold in ms |
| `database.monitor_connections` | `VAAHSIGNOZ_DB_MONITOR_CONNECTIONS` | `true` | Monitor connection pool |
| `database.error_log_level` | `VAAHSIGNOZ_DB_ERROR_LOG_LEVEL` | `critical` | Log level for DB errors |

### Logging

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `logging.capture_php_errors` | `VAAHSIGNOZ_CAPTURE_PHP_ERRORS` | `true` | Capture warnings/notices/deprecations |
| `logging.capture_fatal_errors` | `VAAHSIGNOZ_CAPTURE_FATAL` | `true` | Capture fatal errors via shutdown |
| `logging.otlp_handler` | `VAAHSIGNOZ_OTLP_HANDLER` | `true` | Inject OTLP handler into log channels |

### Security

| Config Key | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| `security.mask_keys` | — | `['password', 'token', ...]` | Keys to redact from request data |
| `security.max_request_body_size` | `VAAHSIGNOZ_MAX_REQUEST_BODY` | `0` | Max body size in bytes (`0` = disable) |
| `security.pii_mask` | `VAAHSIGNOZ_PII_MASK` | `false` | Hash `user.email` and `user.name` |

---

## Collected Metrics

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `http.requests.total` | Counter | `method`, `route`, `status_code` | Total HTTP requests |
| `http.duration` | Histogram | `method`, `route` | Request duration in ms |
| `http.active_requests` | UpDownCounter | — | Currently active requests |
| `db.query.duration` | Histogram | `db.system`, `db.connection_name` | Query duration in ms |
| `db.query.total` | Counter | `db.system`, `db.connection_name` | Total queries |
| `db.slow_queries.total` | Counter | `db.system`, `route` | Queries exceeding threshold |
| `cache.operations.total` | Counter | `operation` | Cache hits, misses, writes, forgets |
| `exceptions.total` | Counter | `type` | Total exceptions |
| `process.memory_usage_bytes` | Gauge | — | Memory usage |
| `db.n_plus_one.total` | Counter | `table`, `route` | N+1 query detections |

---

## Advanced Usage

### Custom Instrumentation

Register your own event listeners or instrumentation hooks via the `VaahSignoz` facade:

```php
use WebReinvent\VaahSignoz\Facades\VaahSignoz;

VaahSignoz::registerInstrumentation(function () {
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
        $span = \WebReinvent\VaahSignoz\Tracer\TracerFactory::createSpan('user.login', [
            'user.id' => $event->user->id,
        ]);
        $span->end();
    });
});
```

### Manual Spans

Create spans programmatically anywhere in your code:

```php
use WebReinvent\VaahSignoz\Tracer\TracerFactory;

$span = TracerFactory::createSpan('order.process', [
    'order.id' => $order->id,
    'order.amount' => $order->total,
]);

// ... do work ...

$span->end();
```

### Manual Metrics

```php
use WebReinvent\VaahSignoz\Meter\MeterFactory;

// Counter
MeterFactory::counter('orders.total', '', 'Total orders processed')
    ->add(1, ['status' => 'completed']);

// Histogram
MeterFactory::histogram('order.processing_time', 'ms', 'Order processing time')
    ->record($durationMs, ['method' => 'POST']);

// UpDownCounter (gauge-like)
MeterFactory::gauge('orders.active', '', 'Currently processing orders')
    ->add(1);
```

### Disabling in Production

```env
VAAHSIGNOZ_ENABLED=false
```

This disables all instrumentation with zero overhead.

### Sampling in Production

To reduce overhead, use probability-based sampling:

```env
OTEL_SAMPLER=tracebased_per_million
OTEL_SAMPLER_RATIO=0.1  # Sample 10% of traces
```

---

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                    Laravel Application               │
│                                                      │
│  ┌──────────────────────────────────────────────┐  │
│  │    RequestInstrumentation (Middleware)        │  │
│  │  ┌────────────────────────────────────────┐  │  │
│  │  │    TracerFactory (TracerProvider)       │  │  │
│  │  │  ├── BatchSpanProcessor                 │  │  │
│  │  │  ├── SpanExporter → OTLP HTTP           │  │  │
│  │  │  └── Sampler (configurable)             │  │  │
│  │  └────────────────────────────────────────┘  │  │
│  │  ┌────────────────────────────────────────┐  │  │
│  │  │    MeterFactory (MeterProvider)         │  │  │
│  │  │  ├── ExportingReader                    │  │  │
│  │  │  └── MetricExporter → OTLP HTTP         │  │  │
│  │  └────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────┘  │
│                                                      │
│  Instrumentations:                                   │
│  ├── QueryInstrumentation        (DB queries)        │
│  ├── CacheInstrumentation        (cache ops)         │
│  ├── LogInstrumentation          (app logs)          │
│  ├── ExceptionInstrumentation    (exceptions)        │
│  ├── ClientInstrumentation       (HTTP calls)        │
│  ├── QueueInstrumentation        (queue jobs)        │
│  ├── EventInstrumentation        (Laravel events)    │
│  ├── ViewInstrumentation         (view rendering)    │
│  ├── NPlusOneDetector            (N+1 patterns)      │
│  ├── DatabaseErrorInstrumentation (DB errors)        │
│  ├── TransactionInstrumentation  (transactions)      │
│  ├── PhpErrorInstrumentation     (PHP errors)        │
│  └── ConnectionMonitorInstrument (connections)       │
└─────────────────────────────────────────────────────┘
                          │
                    OTLP HTTP (4318)
                          │
                    ┌─────▼─────┐
                    │   SigNoz   │
                    └───────────┘
```

---

## Requirements

- **PHP** 8.0+
- **Laravel** 8.x, 9.x, or 10.x
- **OpenTelemetry SDK** `^1.2` (auto-installed as dependency)
- **SigNoz** or any OTLP-compatible backend

> **Note:** `DatabaseBusy` event monitoring and `DB::whenQueryingForLongerThan()` require Laravel 9+. These features are gracefully disabled on Laravel 8.

---

## Troubleshooting

### No data in SigNoz?

1. Check that your OTLP endpoint is correct:
   ```env
   OTEL_EXPORTER_OTLP_ENDPOINT=http://your-signoz-host:4318/v1/traces
   ```

2. Verify connectivity:
   ```bash
   curl -v http://your-signoz-host:4318/v1/traces
   ```

3. Enable debug logging to see errors:
   ```env
   APP_DEBUG=true
   ```

### Spans missing at application shutdown?

The package registers an `$app->terminating()` hook to flush spans and metrics. If using a custom termination handler, ensure it doesn't exit before this hook fires.

### High memory usage?

Enable sampling to reduce the number of spans:

```env
OTEL_SAMPLER=tracebased_per_million
OTEL_SAMPLER_RATIO=0.01  # 1% sampling
```

Or disable specific instrumentations:

```env
VAAHSIGNOZ_INSTRUMENT_EVENT=false
VAAHSIGNOZ_INSTRUMENT_VIEW=false
```

---

## License

The MIT License (MIT).
