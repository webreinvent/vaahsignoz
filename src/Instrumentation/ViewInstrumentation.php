<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\View;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;

class ViewInstrumentation
{
    /**
     * Track render start times per view.
     * Key: view name + request hash to avoid collisions.
     */
    protected static $renderStarts = [];

    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.view', false)) {
            return;
        }

        // Track render start time
        View::creator('*', function ($view) {
            $name = $view->getName();
            $key = $name . '-' . microtime(true);
            self::$renderStarts[$key] = [
                'start' => microtime(true),
                'view' => $name,
            ];

            // Set a reference on the view instance so composer can access it
            if (property_exists($view, 'obLevel') || method_exists($view, 'with')) {
                $view->with('__signoz_render_key', $key);
            }
        });

        // Track render end and measure duration
        View::composer('*', function ($view) {
            $name = $view->getName();

            $span = TracerFactory::createSpan('view.render', [
                'view.name' => $name,
            ]);
            $span->end();

            // Record counter
            MeterFactory::counter('views.rendered')->add(1, [
                'view' => $name,
            ]);
        });
    }
}
