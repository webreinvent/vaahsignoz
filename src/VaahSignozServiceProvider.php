<?php

namespace WebReinvent\VaahSignoz;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\CachesRoutes;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

use WebReinvent\VaahSignoz\Instrumentation\ViewInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ClientInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\TransactionInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\LogInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ConnectionMonitorInstrumentation;

class VaahSignozServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vaahsignoz.php', 'vaahsignoz');

        // Always register singleton so the Facade works even when disabled.
        // The VaahSignoz class checks `enabled` in each method.
        $this->app->singleton('vaahsignoz', function ($app) {
            return new VaahSignoz();
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/vaahsignoz.php' => config_path('vaahsignoz.php'),
        ], 'config');

        if (!config('vaahsignoz.enabled')) {
            return;
        }

        // Register a 'signoz' log channel so Log::channel('signoz') always works.
        // Falls back to the default log channel if config/logging.php doesn't define it.
        $this->registerLogChannel();

        // Boot all instrumentations via the orchestrator
        $signoz = $this->app->make('vaahsignoz');
        $signoz->autoInstrument();

        // Register shutdown hook to flush spans and metrics before PHP exits
        $this->app->terminating(function () {
            TracerFactory::shutdown();
            MeterFactory::shutdown();
            MeterFactory::reset();

            // Clear correlation IDs to prevent leaking across requests
            // (critical for CLI/queue workers where static state persists)
            InstrumentationHelper::clearCorrelationIds();

            // Clear static state across all instrumentations to prevent
            // memory leaks in PHP-FPM (static properties persist between requests)
            $this->clearStaticState();
        });
    }

    /**
     * Clear all static state from instrumentation classes.
     * Prevents memory leaks in PHP-FPM where static properties
     * persist between requests.
     */
    protected function clearStaticState()
    {
        // ViewInstrumentation: $renderStarts accumulates per-view-render entries
        if (class_exists(ViewInstrumentation::class)) {
            $ref = new \ReflectionProperty(ViewInstrumentation::class, 'renderStarts');
            $ref->setValue(null, []);
        }

        // ClientInstrumentation: $activeSpans, $pendingHeaders leak on timeout/abort
        if (class_exists(ClientInstrumentation::class)) {
            $ref1 = new \ReflectionProperty(ClientInstrumentation::class, 'activeSpans');
            $ref1->setValue(null, []);
            $ref2 = new \ReflectionProperty(ClientInstrumentation::class, 'pendingHeaders');
            $ref2->setValue(null, []);
        }

        // TransactionInstrumentation: $activeTransactions leak on unhandled exceptions
        if (class_exists(TransactionInstrumentation::class)) {
            $ref = new \ReflectionProperty(TransactionInstrumentation::class, 'activeTransactions');
            $ref->setValue(null, []);
        }

        // LogInstrumentation: $logBuffer should be empty after flush, but clear anyway
        if (class_exists(LogInstrumentation::class)) {
            $ref = new \ReflectionProperty(LogInstrumentation::class, 'logBuffer');
            $ref->setValue(null, []);
        }

        // ConnectionMonitorInstrumentation: $activeConnections grows per query
        if (class_exists(ConnectionMonitorInstrumentation::class)) {
            ConnectionMonitorInstrumentation::reset();
        }
    }

    /**
     * Register a 'signoz' log channel if it doesn't already exist.
     * This prevents "Log [signoz] is not defined" crashes when the package
     * tries to write to Log::channel('signoz').
     */
    protected function registerLogChannel()
    {
        $logConfig = config('logging.channels');

        // Already defined by the user — nothing to do
        if (isset($logConfig['signoz'])) {
            return;
        }

        // Register a simple daily log channel as fallback
        config(['logging.channels.signoz' => [
            'driver' => 'daily',
            'path' => storage_path('logs/signoz.log'),
            'level' => 'debug',
            'days' => 7,
        ]]);
    }
}
