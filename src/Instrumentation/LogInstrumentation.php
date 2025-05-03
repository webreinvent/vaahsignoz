<?php

namespace Webreinvent\VaahSignoz\Instrumentation;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Webreinvent\VaahSignoz\Exceptions\VaahSignozException;
use Webreinvent\VaahSignoz\Tracer\TracerFactory;

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
        $span = $tracer->spanBuilder('log.message')->startSpan();
        $span->setAttribute('log.level', $event->level);
        $span->setAttribute('log.message', $event->message);
        $span->end();
    }
}
