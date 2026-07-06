<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

/**
 * Captures PHP-level errors: warnings, notices, deprecations, and fatal errors.
 */
class PhpErrorInstrumentation
{
    protected static $previousErrorHandler = null;

    public function boot()
    {
        if (!config('vaahsignoz.logging.capture_php_errors', true)) {
            return;
        }

        // Register custom error handler for warnings, notices, deprecations
        self::$previousErrorHandler = set_error_handler(function (int $severity, string $message, string $file, int $line) {
            if ($severity & error_reporting()) {
                $this->handlePhpError($severity, $message, $file, $line);
            }

            // Don't suppress — let PHP continue normally
            return false;
        });

        // Capture fatal errors via shutdown function
        if (config('vaahsignoz.logging.capture_fatal_errors', true)) {
            register_shutdown_function(function () {
                $error = error_get_last();
                if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING])) {
                    $this->handleFatalError($error);
                }
            });
        }
    }

    protected function handlePhpError(int $severity, string $message, string $file, int $line)
    {
        $level = match ($severity) {
            E_WARNING => 'warning',
            E_NOTICE => 'notice',
            E_DEPRECATED => 'warning',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'notice',
            E_USER_DEPRECATED => 'warning',
            E_STRICT => 'notice',
            default => 'info',
        };

        // Create span
        $span = TracerFactory::createSpan('php.error', [
            'php.error.severity' => (string) $severity,
            'php.error.level' => $level,
            'php.error.file' => $file,
            'php.error.line' => (string) $line,
            'php.error.message' => $message,
        ]);
        $span->setStatus(StatusCode::STATUS_ERROR);
        $span->end();

        // Log
        Log::channel('signoz')->log($level, "PHP Error: {$message} in {$file}:{$line}", [
            'severity' => $severity,
            'trace_id' => InstrumentationHelper::getCurrentTraceId(),
        ]);

        // Metric
        try {
            MeterFactory::counter('php.errors.total')->add(1, [
                'severity' => (string) $severity,
                'level' => $level,
            ]);
        } catch (\Throwable $_) {
            // Meter may not be ready
        }
    }

    protected function handleFatalError(array $error)
    {
        $span = TracerFactory::createSpan('php.fatal_error', [
            'php.error.severity' => (string) ($error['type'] ?? 0),
            'php.error.message' => $error['message'] ?? '',
            'php.error.file' => $error['file'] ?? '',
            'php.error.line' => (string) ($error['line'] ?? 0),
        ]);
        $span->setStatus(StatusCode::STATUS_ERROR);
        $span->end();

        Log::channel('signoz')->critical("Fatal Error: {$error['message']} in {$error['file']}:", [
            'line' => $error['line'],
            'severity' => $error['type'],
            'trace_id' => InstrumentationHelper::getCurrentTraceId(),
        ]);
    }
}
