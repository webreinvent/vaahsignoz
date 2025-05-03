<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use OpenTelemetry\API\Trace\StatusCode;

class LogInstrumentation
{

    public function boot()
    {
        try {
            Event::listen(MessageLogged::class, [$this, 'handleMessageLogged']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot log instrumentation.', 0, $e);
        }
    }

    public function handleMessageLogged(MessageLogged $event)
    {

        $tracer = TracerFactory::getTracer();

        $spanName = 'log.' . strtolower($event->level);
        $span = $tracer->spanBuilder($spanName)->startSpan();
        $span->setAttribute('log.level', $event->level);
        $span->setAttribute('log.message', $event->message);

        if (strtolower($event->level) === 'error') {
            $span->setStatus(StatusCode::STATUS_ERROR, 'Error log');
            $span->setAttribute('error', true);
        }

        $span->end();
    }
}
