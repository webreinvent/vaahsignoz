<?php

namespace WebReinvent\VaahSignoz\Helpers;

use OpenTelemetry\API\Globals;

class Telemetry
{
    // Start a span for the incoming HTTP request
    public static function startRequestSpan($request)
    {
        $tracer = Globals::tracerProvider()->getTracer('vaahsignoz-http');

        $span = $tracer->spanBuilder('HTTP '.$request->method())
            ->setAttribute('http.method', $request->method())
            ->setAttribute('http.url', $request->fullUrl())
            ->startSpan();

        return $span;
    }

    // Track DB queries
    public static function trackDbQuery($query)
    {
        $tracer = Globals::tracerProvider()->getTracer('vaahsignoz-db');
        $span = $tracer->spanBuilder('DB Query')
            ->setAttribute('db.statement', $query->sql)
            ->setAttribute('db.bindings', json_encode($query->bindings))
            ->setAttribute('db.time_ms', $query->time)
            ->startSpan();

        $span->end();
    }

    // Logging as span (optional)
    public static function log($level, $message, $context)
    {
        $tracer = Globals::tracerProvider()->getTracer('vaahsignoz-logs');
        $span = $tracer->spanBuilder('log.'.$level)
            ->setAttribute('log.severity', $level)
            ->setAttribute('log.message', $message)
            ->setAttribute('log.context', json_encode($context ?? []))
            ->startSpan();
        $span->end();
    }

    // Register on exception handler
    public static function trackException($exception)
    {
        $tracer = Globals::tracerProvider()->getTracer('vaahsignoz-error');
        $span = $tracer->spanBuilder('Exception')
            ->setAttribute('exception.type', get_class($exception))
            ->setAttribute('exception.message', $exception->getMessage())
            ->setAttribute('exception.stacktrace', $exception->getTraceAsString())
            ->startSpan();
        $span->end();
    }
}
