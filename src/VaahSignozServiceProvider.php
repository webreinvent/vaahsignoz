<?php

namespace WebReinvent\VaahSignoz;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\CachesRoutes;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;

class VaahSignozServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vaahsignoz.php', 'vaahsignoz');

        if (config('vaahsignoz.enabled')) {
            $this->app->singleton('vaahsignoz', function ($app) {
                return new VaahSignoz();
            });
        }
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
        });
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
            'level' => config('log.level', 'debug'),
            'days' => 7,
        ]]);
    }
}
