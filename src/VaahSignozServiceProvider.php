<?php

namespace WebReinvent\VaahSignoz;

use Illuminate\Support\ServiceProvider;

class VaahSignozServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/vaahsignoz.php' => config_path('vaahsignoz.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__.'/../config/vaahsignoz.php', 'vaahsignoz'
        );

        // Bootstrap SigNoz integration here (e.g., register middleware, listeners)
        \WebReinvent\VaahSignoz\Helpers\SigNoz::init();
    }

    public function register()
    {
        //
    }
}
