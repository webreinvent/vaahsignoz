<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\KeyForgotten;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
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
            // Only register event listeners if cache instrumentation is enabled
            if (!isset($this->vaahSignozConfig['instrumentation']['cache']) || 
                !$this->vaahSignozConfig['instrumentation']['cache']) {
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
        
        // Use the standardized span creation method
        $span = TracerFactory::createSpan('cache.hit', $attributes);
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // End the span to ensure it's sent to SignOz
        $span->end();
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
        
        // Use the standardized span creation method
        $span = TracerFactory::createSpan('cache.miss', $attributes);
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // End the span to ensure it's sent to SignOz
        $span->end();
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
        
        // Use the standardized span creation method
        $span = TracerFactory::createSpan('cache.write', $attributes);
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // End the span to ensure it's sent to SignOz
        $span->end();
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
        
        // Use the standardized span creation method
        $span = TracerFactory::createSpan('cache.forget', $attributes);
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // End the span to ensure it's sent to SignOz
        $span->end();
    }
    
    /**
     * Add standard attributes to a span
     */
    protected function addStandardAttributes($span)
    {
        $span->setAttribute('service.name', $this->vaahSignozConfig['otel']['service_name'] ?? 'laravel-app');
        $span->setAttribute('service.version', $this->vaahSignozConfig['otel']['version'] ?? '1.0.0');
        $span->setAttribute('deployment.environment', $this->vaahSignozConfig['otel']['environment'] ?? 'production');
        $span->setAttribute('host.name', InstrumentationHelper::getHostIdentifier());
        
        // Add current trace and span IDs for correlation
        $traceId = InstrumentationHelper::getCurrentTraceId();
        $spanId = InstrumentationHelper::getCurrentSpanId();
        
        if ($traceId) {
            $span->setAttribute('trace_id', $traceId);
            
            if ($spanId) {
                $span->setAttribute('span_id', $spanId);
            }
        }
    }
}
