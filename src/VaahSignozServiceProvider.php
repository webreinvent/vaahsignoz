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

        // Boot all instrumentations via the orchestrator
        $signoz = $this->app->make('vaahsignoz');
        $signoz->autoInstrument();

        // Register shutdown hook to flush spans and metrics before PHP exits
        $this->app->terminating(function () {
            TracerFactory::shutdown();
            MeterFactory::shutdown();
        });
    }
}
