<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Exceptions\Events\ExceptionOccurred;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;

class ExceptionInstrumentation
{
    public function boot()
    {
        try {
            Event::listen(ExceptionOccurred::class, [$this, 'handleException']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot exception instrumentation.', 0, $e);
        }
    }

    public function handleException(ExceptionOccurred $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('exception')->startSpan();
        $span->setAttribute('exception.type', get_class($event->exception));
        $span->setAttribute('exception.message', $event->exception->getMessage());
        $span->setAttribute('exception.file', $event->exception->getFile());
        $span->setAttribute('exception.line', $event->exception->getLine());
        $span->end();
    }
}
