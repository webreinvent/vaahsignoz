<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Contracts\Events\Dispatcher;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;

class EventInstrumentation
{
    protected static $listenMap = [];

    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.event', false)) {
            return;
        }

        $eventDispatcher = app(Dispatcher::class);

        // Listen to all dispatched events via the dispatcher's global listener
        $eventDispatcher->listen('*', function ($eventName, array $payload) {
            if (config('vaahsignoz.instrumentations.event') === false) {
                return;
            }

            $span = TracerFactory::createSpan('event.dispatch', [
                'event.name' => $eventName,
                'event.payload_count' => count($payload),
            ]);
            $span->end();

            MeterFactory::counter('events.dispatched')->add(1, [
                'event' => $eventName,
            ]);
        });
    }
}
