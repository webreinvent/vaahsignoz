<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

/**
 * Captures all database errors: PDO exceptions, connection failures,
 * deadlocks, lock timeouts, lost connections, schema errors.
 */
class DatabaseErrorInstrumentation
{
    public function boot()
    {
        if (!config('vaahsignoz.database.capture_errors', true)) {
            return;
        }

        // Use DB::listen() to capture slow queries and error indicators.
        // QueryExceptions are caught by the global exception handler
        // (ExceptionInstrumentation), so we augment them with db-specific
        // classification by wrapping the listener.
        $this->registerQueryErrorListener();
    }

    /**
     * Register listeners to capture database query errors and slow queries.
     */
    protected function registerQueryErrorListener()
    {
        $threshold = config('vaahsignoz.database.slow_query_threshold_ms', 100);

        DB::listen(function ($data) use ($threshold) {
            // Capture slow queries
            if ($data->time >= $threshold) {
                $this->captureSlowQuery($data);
            }
        });
    }

    /**
     * Capture a slow query as a span + metric.
     */
    public function captureSlowQuery($data)
    {
        $driverName = 'unknown';

        try {
            $driverName = DB::getDriverName() ?? 'unknown';
        } catch (\Throwable $_) {
            // Connection may be gone
        }

        $span = TracerFactory::createSpan('db.slow_query', [
            'db.system' => $driverName,
            'db.statement' => $data->sql ?? '',
            'db.duration_ms' => $data->time,
            'db.connection_name' => $data->connectionName ?? 'default',
        ]);
        InstrumentationHelper::setSpanStatus($span, 'ok');
        $span->end();

        // Increment metric
        try {
            MeterFactory::counter('db.slow_queries.total')->add(1, [
                'db.system' => $driverName,
                'route' => request() && request()->route() ? (request()->route()->getName() ?? request()->route()->uri() ?? '') : '',
            ]);
        } catch (\Throwable $_) {
            // Meter may not be ready
        }
    }

    /**
     * Handle a QueryException that was caught by the global exception handler.
     * This is called by ExceptionInstrumentation when it encounters a QueryException.
     */
    public function handleQueryException(QueryException $e)
    {
        $this->captureDatabaseError($e);
    }

    public function captureDatabaseError(QueryException $e)
    {
        $errorType = $this->classifyError($e);
        $connection = $e->connection ?? 'default';
        $driver = $e->connection ?? get_class($e);
        $driverName = 'unknown';

        try {
            $driverName = $e->connection->getDriverName() ?? 'unknown';
        } catch (\Throwable $_) {
            // Connection may be gone
        }

        // Create span
        $span = TracerFactory::createSpan("db.error.{$errorType}", [
            'db.system' => $driverName,
            'db.connection_name' => $connection,
            'db.statement' => $e->getSql(),
            'db.bindings' => json_encode($e->getBindings()),
            'exception.type' => get_class($e),
            'exception.message' => $e->getMessage(),
            'exception.code' => $e->getCode(),
            'db.error_type' => $errorType,
        ]);
        InstrumentationHelper::setSpanStatus($span, 'error');

        if ($e->getPrevious()) {
            $span->recordException($e->getPrevious());
        }

        $span->end();

        // Increment metric
        try {
            MeterFactory::counter('db.errors.total')->add(1, [
                'error_type' => $errorType,
                'db.system' => $driverName,
            ]);
        } catch (\Throwable $_) {
            // Meter may not be ready
        }

        // Log
        $logLevel = config('vaahsignoz.database.error_log_level', 'critical');

        try {
            Log::channel('signoz')->log($logLevel, "Database error [{$errorType}]: {$e->getMessage()}", [
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'error_type' => $errorType,
                'code' => $e->getCode(),
                'connection' => $connection,
                'driver' => $driverName,
                'trace_id' => InstrumentationHelper::getCurrentTraceId(),
            ]);
        } catch (\Throwable $_) {
            Log::log($logLevel, "Database error [{$errorType}]: {$e->getMessage()}", [
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'error_type' => $errorType,
                'trace_id' => InstrumentationHelper::getCurrentTraceId(),
            ]);
        }
    }

    /**
     * Classify the database error by its error code and message
     */
    protected function classifyError(QueryException $e): string
    {
        $code = $e->getCode();
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'deadlock') || in_array($code, ['40001', '40P01'])) {
            return 'deadlock';
        }

        if (str_contains($message, 'lock wait timeout') || in_array($code, ['1205', '55P03'])) {
            return 'lock_timeout';
        }

        if (str_contains($message, 'gone away') || str_contains($message, 'connection closed') || in_array($code, ['2006', '2013'])) {
            return 'connection_lost';
        }

        if (str_contains($message, 'access denied') || str_contains($message, 'unable to connect') || str_contains($message, 'connection refused')) {
            return 'connection_failed';
        }

        if (str_contains($message, 'too many connections')) {
            return 'connection_pool_exhausted';
        }

        if (str_contains($message, "doesn't exist") || str_contains($message, 'no such table') || str_contains($message, 'unknown column')) {
            return 'schema_error';
        }

        return 'general_error';
    }
}
