<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\View;
use Illuminate\View\View;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;

class ViewInstrumentation
{
    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.view', false)) {
            return;
        }

        View::creator('*', function ($view) {
            $view->startComponentRendering();
        });

        View::composer('*', function ($view) {
            $name = $view->getName();
            $span = TracerFactory::createSpan('view.render', [
                'view.name' => $name,
            ]);

            $span->end();

            MeterFactory::histogram('view.render.duration_ms')
                ->record(1, ['view' => $name]);
        });

        View::creator('*', function ($view) {
            MeterFactory::counter('views.rendered')->add(1, [
                'view' => $view->getName(),
            ]);
        });
    }
}
