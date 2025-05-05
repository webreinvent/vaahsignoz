<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use Throwable;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

class ExceptionInstrumentation
{
    protected $setupConfig;

    public function boot()
    {
        $this->setupConfig = TracerFactory::getSetupConfig();

        // Listen for Laravel exception events via the logging system
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (isset($event->context['exception']) && $event->context['exception'] instanceof Throwable) {
                $this->handleException($event->context['exception'], $event->level);
            }
        });

        // Also register a global exception handler as a fallback
        $this->registerGlobalExceptionHandler();
    }

    /**
     * Register a global exception handler to catch exceptions that might not be logged
     */
    protected function registerGlobalExceptionHandler()
    {
        // Set a global exception handler as a last resort
        set_exception_handler(function (Throwable $exception) {
            try {
                $this->handleException($exception, 'critical');
            } catch (\Throwable $e) {
                // Last resort logging to prevent infinite loops
                if (config('app.debug')) {
                    error_log('SigNoz exception handler error: ' . $e->getMessage());
                }
            }

            // Re-throw to let Laravel handle it normally
            if (is_callable($this->previousHandler)) {
                call_user_func($this->previousHandler, $exception);
            }
        });
    }

    /**
     * Handle the exception by creating a span and logging it to SigNoz
     */
    protected function handleException(Throwable $exception, string $level)
    {
        try {
            // Get the tracer
            $tracer = TracerFactory::getTracer();

            // Create a new span for the exception
            $span = $tracer->spanBuilder('exception.' . get_class($exception))
                ->setAttribute('exception.type', get_class($exception))
                ->setAttribute('exception.message', $exception->getMessage())
                ->setAttribute('exception.stacktrace', $exception->getTraceAsString())
                ->setAttribute('exception.file', $exception->getFile())
                ->setAttribute('exception.line', $exception->getLine())
                ->setAttribute('log.level', $level)
                ->setAttribute('service.name', $this->setupConfig['serviceName'])
                ->setAttribute('service.version', $this->setupConfig['version'])
                ->setAttribute('deployment.environment', $this->setupConfig['environment'])
                ->setAttribute('host.name', InstrumentationHelper::getHostIdentifier())
                ->startSpan();

            // Set error status
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            // Add code context if available
            if (file_exists($exception->getFile())) {
                $fileLines = file($exception->getFile());
                if (isset($fileLines[$exception->getLine() - 1])) {
                    $span->setAttribute('exception.code_line', trim($fileLines[$exception->getLine() - 1]));
                }

                // Add a few lines before and after for context
                $contextLines = [];
                $startLine = max(0, $exception->getLine() - 3);
                $endLine = min(count($fileLines), $exception->getLine() + 2);

                for ($i = $startLine; $i < $endLine; $i++) {
                    $contextLines[] = ($i + 1) . ': ' . trim($fileLines[$i]);
                }

                $span->setAttribute('exception.code_context', implode("\n", $contextLines));
            }

            // Add request information if available
            if (request()) {
                $span->setAttribute('http.method', request()->method());
                $span->setAttribute('http.url', request()->fullUrl());
                $span->setAttribute('http.user_agent', request()->userAgent() ?? 'unknown');
                $span->setAttribute('http.client_ip', request()->ip());
            }

            // End the span to send it to SigNoz
            $span->end();

        } catch (\Throwable $e) {
            // Prevent infinite loops if there's an error in our exception handling
            if (config('app.debug')) {
                Log::error('SigNoz exception instrumentation error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get the host identifier - prefer domain name over hostname
     *
     * @return string
     */
    protected function getHostIdentifier()
    {
        return InstrumentationHelper::getHostIdentifier();
    }

    // Store the previous exception handler
    protected $previousHandler;

    public function __construct()
    {
        $this->previousHandler = set_exception_handler(function() {
            restore_exception_handler();
            return $this->previousHandler;
        });
        restore_exception_handler();
    }
}
