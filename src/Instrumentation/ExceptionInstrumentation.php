<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use Throwable;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;

class ExceptionInstrumentation
{
    protected $setupConfig;
    protected $app;
    
    public function boot()
    {
        $this->setupConfig = TracerFactory::getSetupConfig();
        $this->app = app();
        
        // Listen for Laravel exception events via the logging system
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (isset($event->context['exception']) && $event->context['exception'] instanceof Throwable) {
                $this->handleException($event->context['exception'], $event->level);
            }
        });
        
        // Register a global exception handler as a fallback
        $this->registerGlobalExceptionHandler();
        
        // Override Laravel's exception handler to ensure we capture all exceptions
        $this->extendLaravelExceptionHandler();
    }
    
    /**
     * Extend Laravel's exception handler to ensure we capture all exceptions
     */
    protected function extendLaravelExceptionHandler()
    {
        if ($this->app->bound(ExceptionHandlerContract::class)) {
            $originalHandler = $this->app->make(ExceptionHandlerContract::class);
            
            $this->app->singleton(ExceptionHandlerContract::class, function ($app) use ($originalHandler) {
                return new class($originalHandler, $this) extends ExceptionHandler {
                    protected $originalHandler;
                    protected $instrumentationClass;
                    
                    public function __construct($originalHandler, $instrumentationClass) {
                        $this->originalHandler = $originalHandler;
                        $this->instrumentationClass = $instrumentationClass;
                    }
                    
                    public function report(Throwable $e)
                    {
                        // Track the exception in SigNoz
                        $this->instrumentationClass->handleException($e, 'error');
                        
                        // Call the original handler
                        return $this->originalHandler->report($e);
                    }
                    
                    public function render($request, Throwable $e)
                    {
                        return $this->originalHandler->render($request, $e);
                    }
                    
                    public function renderForConsole($output, Throwable $e)
                    {
                        return $this->originalHandler->renderForConsole($output, $e);
                    }
                    
                    public function shouldReport(Throwable $e)
                    {
                        return $this->originalHandler->shouldReport($e);
                    }
                };
            });
        }
    }
    
    /**
     * Register a global exception handler to catch exceptions that might not be logged
     */
    protected function registerGlobalExceptionHandler()
    {
        // Set a global exception handler as a last resort
        $previousHandler = set_exception_handler(function (Throwable $exception) use (&$previousHandler) {
            try {
                $this->handleException($exception, 'critical');
            } catch (\Throwable $e) {
                // Last resort logging to prevent infinite loops
                if (config('app.debug')) {
                    error_log('SigNoz exception handler error: ' . $e->getMessage());
                }
            }
            
            // Re-throw to let Laravel handle it normally
            if (is_callable($previousHandler)) {
                call_user_func($previousHandler, $exception);
            }
        });
    }
    
    /**
     * Handle the exception by creating a span and logging it to SigNoz
     */
    public function handleException(Throwable $exception, string $level)
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
                
                // Add route information if available
                if (request()->route()) {
                    $span->setAttribute('http.route', request()->route()->getName() ?? request()->route()->uri());
                    
                    // Add controller and action if available
                    $action = request()->route()->getAction();
                    if (isset($action['controller'])) {
                        $span->setAttribute('http.controller', $action['controller']);
                    }
                }
            }
            
            // End the span to send it to SigNoz
            $span->end();
            
            // Also log the exception to ensure it's captured in logs
            Log::error('Exception tracked by VaahSignoz: ' . $exception->getMessage(), [
                'exception' => $exception,
                'vaahsignoz_tracked' => true
            ]);
            
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
}
