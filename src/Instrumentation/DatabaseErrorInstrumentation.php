<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use OpenTelemetry\API\Trace\StatusCode;
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

        // Register with Laravel's exception handler
        if (app()->bound(ExceptionHandlerContract::class)) {
            app(ExceptionHandlerContract::class)->report(function (QueryException $e) {
                $this->captureDatabaseError($e);
            });
        }
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
        $span->setStatus(StatusCode::STATUS_ERROR);

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

        Log::channel('signoz')->log($logLevel, "Database error [{$errorType}]: {$e->getMessage()}", [
            'sql' => $e->getSql(),
            'bindings' => $e->getBindings(),
            'error_type' => $errorType,
            'code' => $e->getCode(),
            'connection' => $connection,
            'driver' => $driverName,
            'trace_id' => InstrumentationHelper::getCurrentTraceId(),
        ]);
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
