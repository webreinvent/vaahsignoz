<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\QueryExecuted;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;
use Illuminate\Support\Facades\Log;

class QueryInstrumentation
{
    protected static $slowThreshold = 100;

    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.query', true)) {
            return;
        }

        try {
            self::$slowThreshold = config('vaahsignoz.database.slow_query_threshold_ms', 100);

            Event::listen(QueryExecuted::class, static function (QueryExecuted $event) {
                // $event->connection can be null in some Laravel versions / CLI contexts
                $driver = $event->connection ? $event->connection->getDriverName() : $event->connectionName;

                // Only record metrics for every query (lightweight — no span allocation)
                self::recordMetrics($event, $driver);

                // Only create spans for slow queries
                $isSlow = $event->time >= self::$slowThreshold;

                if ($isSlow && config('vaahsignoz.database.capture_slow_queries', true)) {
                    self::handleSlowQueryEvent($event, $driver);
                }
            });
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot query instrumentation.', 0, $e);
        }
    }

    /**
     * Handle slow query — create span + log + metric
     */
    protected static function handleSlowQueryEvent(QueryExecuted $event, string $driver)
    {
        $route = request() && request()->route() ? (request()->route()->getName() ?? request()->path()) : 'artisan';

        // Span — only for slow queries
        $span = TracerFactory::createSpan('db.slow_query', [
            'db.system' => $driver,
            'db.statement' => $event->sql,
            'db.time' => $event->time,
            'db.slow_threshold_ms' => self::$slowThreshold,
            'db.connection_name' => $event->connectionName,
        ]);
        InstrumentationHelper::setSpanStatus($span, 'error', "Query took {$event->time}ms (threshold: " . self::$slowThreshold . "ms)");
        $span->end();

        // Log
        try {
            Log::channel('signoz')->warning("Slow query: {$event->sql} ({$event->time}ms)", [
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
                'driver' => $driver,
                'threshold_ms' => self::$slowThreshold,
                'route' => $route,
                'trace_id' => InstrumentationHelper::getCurrentTraceId(),
            ]);
        } catch (\Throwable $_) {
            Log::warning("Slow query: {$event->sql} ({$event->time}ms)", [
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
                'threshold_ms' => self::$slowThreshold,
                'route' => $route,
                'trace_id' => InstrumentationHelper::getCurrentTraceId(),
            ]);
        }

        // Metric
        try {
            MeterFactory::counter('db.slow_queries.total')->add(1, [
                'db.system' => $driver,
                'route' => $route,
            ]);
        } catch (\Throwable $_) {
        }
    }

    /**
     * Record DB query metrics (lightweight — no span allocation)
     */
    protected static function recordMetrics(QueryExecuted $event, string $driver)
    {
        if (!config('vaahsignoz.metrics.db', true)) {
            return;
        }

        try {
            MeterFactory::counter('db.query.total')
                ->add(1, [
                    'db.system' => $driver,
                    'db.connection_name' => $event->connectionName,
                ]);
        } catch (\Throwable $_) {
            // Meter may not be ready
        }
    }
}
