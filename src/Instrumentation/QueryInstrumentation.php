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
    protected $slowThreshold;

    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.query', true)) {
            return;
        }

        try {
            $this->slowThreshold = config('vaahsignoz.database.slow_query_threshold_ms', 100);

            Event::listen(QueryExecuted::class, [$this, 'handleQuery']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot query instrumentation.', 0, $e);
        }
    }

    public function handleQuery(QueryExecuted $event)
    {
        // $event->connection can be null in some Laravel versions / CLI contexts
        $driver = $event->connection ? $event->connection->getDriverName() : $event->connectionName;

        $span = TracerFactory::createSpan('db.query', [
            'db.system' => $driver,
            'db.statement' => $event->sql,
            'db.bindings' => json_encode($event->bindings),
            'db.time' => $event->time,
            'db.connection_name' => $event->connectionName,
        ]);
        $span->end();

        // Metrics
        $this->recordMetrics($event);

        // Slow query — handled via the same QueryExecuted listener
        // (DB::whenQueryingForLongerThan passes unreliable data types across Laravel versions)
        if ($event->time >= $this->slowThreshold && config('vaahsignoz.database.capture_slow_queries', true)) {
            $this->handleSlowQueryEvent($event);
        }
    }

    /**
     * Handle slow query from a proper QueryExecuted event — create span + metric + log
     */
    protected function handleSlowQueryEvent(QueryExecuted $event)
    {
        $route = request() && request()->route() ? (request()->route()->getName() ?? request()->path()) : 'artisan';
        $driver = $event->connection ? $event->connection->getDriverName() : $event->connectionName;

        // Span
        $span = TracerFactory::createSpan('db.slow_query', [
            'db.system' => $driver,
            'db.statement' => $event->sql,
            'db.time' => $event->time,
            'db.slow_threshold_ms' => $this->slowThreshold,
            'db.connection_name' => $event->connectionName,
        ]);
        InstrumentationHelper::setSpanStatus($span, 'error', "Query took {$event->time}ms (threshold: {$this->slowThreshold}ms)");
        $span->end();

        // Log
        try {
            Log::channel('signoz')->warning("Slow query: {$event->sql} ({$event->time}ms)", [
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
                'driver' => $driver,
                'threshold_ms' => $this->slowThreshold,
                'route' => $route,
                'trace_id' => InstrumentationHelper::getCurrentTraceId(),
            ]);
        } catch (\Throwable $_) {
            Log::warning("Slow query: {$event->sql} ({$event->time}ms)", [
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
                'threshold_ms' => $this->slowThreshold,
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
     * Record DB query metrics
     */
    protected function recordMetrics(QueryExecuted $event)
    {
        if (!config('vaahsignoz.metrics.db', true)) {
            return;
        }

        try {
            $driver = $event->connection ? $event->connection->getDriverName() : $event->connectionName;

            // Histogram: query duration
            MeterFactory::histogram('db.query.duration')
                ->record($event->time, [
                    'db.system' => $driver,
                    'db.connection_name' => $event->connectionName,
                ]);

            // Counter: total queries
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
