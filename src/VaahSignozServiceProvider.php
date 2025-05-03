<?php

namespace WebReinvent\VaahSignoz;

use Illuminate\Support\ServiceProvider;

class VaahSignozServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaahsignoz.php', 'vaahsignoz');

        $this->app->singleton('vaahsignoz', function ($app) {
            return new VaahSignoz();
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/vaahsignoz.php' => config_path('vaahsignoz.php'),
        ], 'config');

        if (config('vaahsignoz.enabled')) {
            $signoz = $this->app->make('vaahsignoz');
            $signoz->autoInstrument();
        }
    }
}
