<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;

class HttpMetrics
{
    /**
     * Record HTTP request metrics
     */
    public static function record(string $method, string $route, int $statusCode, float $durationMs)
    {
        if (!config('vaahsignoz.metrics.http', true)) {
            return;
        }

        // Counter: total requests
        MeterFactory::counter('http.requests.total')
            ->add(1, [
                'method' => strtoupper($method),
                'status' => (string) $statusCode,
                'route' => $route,
            ]);

        // Histogram: request duration
        MeterFactory::histogram('http.duration')
            ->record($durationMs, [
                'method' => strtoupper($method),
                'status' => (string) $statusCode,
                'route' => $route,
            ]);
    }

    /**
     * Record process memory metrics
     */
    public static function recordMemory()
    {
        if (!config('vaahsignoz.metrics.process', true)) {
            return;
        }

        MeterFactory::gauge('process.memory_usage_bytes')
            ->add(memory_get_usage(true), []);

        MeterFactory::gauge('process.peak_memory_usage_bytes')
            ->add(memory_get_peak_usage(true), []);
    }
}
