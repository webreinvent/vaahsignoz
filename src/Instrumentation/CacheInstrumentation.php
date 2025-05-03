<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\KeyForgotten;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;

class CacheInstrumentation
{
    public function boot()
    {
        try {
            Event::listen(CacheHit::class, [$this, 'handleHit']);
            Event::listen(CacheMissed::class, [$this, 'handleMiss']);
            Event::listen(KeyWritten::class, [$this, 'handleWrite']);
            Event::listen(KeyForgotten::class, [$this, 'handleForget']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot cache instrumentation.', 0, $e);
        }
    }

    public function handleHit(CacheHit $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('cache.hit')->startSpan();
        $span->setAttribute('cache.key', $event->key);
        $span->end();
    }

    public function handleMiss(CacheMissed $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('cache.missed')->startSpan();
        $span->setAttribute('cache.key', $event->key);
        $span->end();
    }

    public function handleWrite(KeyWritten $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('cache.write')->startSpan();
        $span->setAttribute('cache.key', $event->key);
        $span->end();
    }

    public function handleForget(KeyForgotten $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('cache.forget')->startSpan();
        $span->setAttribute('cache.key', $event->key);
        $span->end();
    }
}
