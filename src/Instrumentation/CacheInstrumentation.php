<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\KeyForgotten;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

class CacheInstrumentation
{
    /**
     * Configuration for the instrumentation
     */
    protected $vaahSignozConfig;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->vaahSignozConfig = config('vaahsignoz');
    }

    /**
     * Boot the instrumentation
     */
    public function boot()
    {
        try {
            // Fixed: use 'instrumentations' (plural) config key
            if (!isset($this->vaahSignozConfig['instrumentations']['cache']) ||
                !$this->vaahSignozConfig['instrumentations']['cache']) {
                return;
            }

            Event::listen(CacheHit::class, [$this, 'handleHit']);
            Event::listen(CacheMissed::class, [$this, 'handleMiss']);
            Event::listen(KeyWritten::class, [$this, 'handleWrite']);
            Event::listen(KeyForgotten::class, [$this, 'handleForget']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot cache instrumentation.', 0, $e);
        }
    }

    /**
     * Handle cache hit event
     */
    public function handleHit(CacheHit $event)
    {
        $attributes = [
            'cache.key' => $event->key,
            'cache.driver' => $event->tags[0] ?? 'unknown',
            'cache.operation' => 'hit',
            'cache.ttl' => $event->seconds ?? null,
        ];

        $span = TracerFactory::createSpan('cache.hit', $attributes);
        $span->end();

        $this->recordMetrics('hit');
    }

    /**
     * Handle cache miss event
     */
    public function handleMiss(CacheMissed $event)
    {
        $attributes = [
            'cache.key' => $event->key,
            'cache.driver' => $event->tags[0] ?? 'unknown',
            'cache.operation' => 'miss',
        ];

        $span = TracerFactory::createSpan('cache.miss', $attributes);
        $span->end();

        $this->recordMetrics('miss');
    }

    /**
     * Handle cache write event
     */
    public function handleWrite(KeyWritten $event)
    {
        $attributes = [
            'cache.key' => $event->key,
            'cache.driver' => $event->tags[0] ?? 'unknown',
            'cache.operation' => 'write',
            'cache.ttl' => $event->seconds ?? null,
        ];

        $span = TracerFactory::createSpan('cache.write', $attributes);
        $span->end();

        $this->recordMetrics('write');
    }

    /**
     * Handle cache forget event
     */
    public function handleForget(KeyForgotten $event)
    {
        $attributes = [
            'cache.key' => $event->key,
            'cache.driver' => $event->tags[0] ?? 'unknown',
            'cache.operation' => 'forget',
        ];

        $span = TracerFactory::createSpan('cache.forget', $attributes);
        $span->end();

        $this->recordMetrics('forget');
    }

    /**
     * Record cache metrics
     */
    protected function recordMetrics(string $operation)
    {
        if (!config('vaahsignoz.metrics.cache', true)) {
            return;
        }

        try {
            MeterFactory::counter('cache.operations.total')
                ->add(1, ['operation' => $operation]);
        } catch (\Throwable $_) {
        }
    }
}
