<?php
namespace WebReinvent\VaahSignoz;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as LaravelHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WebReinvent\VaahSignoz\Helpers\Telemetry;
use Throwable;

class VaahSignozServiceProvider extends ServiceProvider
{
    public function register()
    {
        dd();
        // Merge package config.
        $this->mergeConfigFrom(__DIR__.'/Config/vaahsignoz.php', 'vaahsignoz');
    }

    public function boot()
    {
        dd();

        // Publish config
        $this->publishes([
            __DIR__.'/Config/vaahsignoz.php' => config_path('vaahsignoz.php'),
        ], 'config');

        // 1. Auto-Instrumentation bootstrap
        if (class_exists(\OpenTelemetry\Instrumentation\Bootstrap::class)) {
            \OpenTelemetry\Instrumentation\Bootstrap::start();
        }

        // 2. Track DB queries
        DB::listen(function ($query) {
            Telemetry::trackDbQuery($query);
        });

        // 3. Track Logs
        Log::listen(function ($level, $message, $context) {
            Telemetry::log($level, $message, $context);
        });

        // 4. Exception Handling (report spans)
        $this->app->resolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'setVaahSignozTelemetry')) {
                $handler->setVaahSignozTelemetry();
            }
        });

        // 5. Register request span middleware globally (optional - can register only on API/web)
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('web', \WebReinvent\VaahSignoz\Middleware\TrackRequestSpan::class);
        $router->pushMiddlewareToGroup('api', \WebReinvent\VaahSignoz\Middleware\TrackRequestSpan::class);
    }
}
