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
use GuzzleHttp\Client;

class ExceptionInstrumentation
{
    protected $setupConfig;
    protected $app;
    protected $endpoint;
    protected $httpClient;
    
    public function boot()
    {
        $this->setupConfig = TracerFactory::getSetupConfig();
        $this->app = app();
        $this->endpoint = $this->setupConfig['endpoint'];
        
        // Initialize HTTP client
        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);
        
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
            // Send the exception as a log with special exception attributes
            $this->sendExceptionToSigNoz($exception, $level);
            
            // Also create a trace for the exception
            $this->createExceptionTrace($exception, $level);
            
        } catch (\Throwable $e) {
            // Prevent infinite loops if there's an error in our exception handling
            if (config('app.debug')) {
                Log::error('SigNoz exception instrumentation error: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Send the exception as a log with special exception attributes
     */
    protected function sendExceptionToSigNoz(Throwable $exception, string $level)
    {
        try {
            // Extract file and line information
            $fileInfo = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'function' => null,
                'class' => get_class($exception)
            ];
            
            // Get the trace as an array
            $trace = $exception->getTrace();
            if (!empty($trace) && isset($trace[0])) {
                if (isset($trace[0]['function'])) {
                    $fileInfo['function'] = $trace[0]['function'];
                }
                if (isset($trace[0]['class'])) {
                    $fileInfo['class'] = $trace[0]['class'];
                }
            }
            
            // Prepare the log data
            $logData = [
                'resourceLogs' => [
                    [
                        'resource' => [
                            'attributes' => [
                                [
                                    'key' => 'service.name',
                                    'value' => ['stringValue' => $this->setupConfig['serviceName']]
                                ],
                                [
                                    'key' => 'service.version',
                                    'value' => ['stringValue' => $this->setupConfig['version']]
                                ],
                                [
                                    'key' => 'deployment.environment',
                                    'value' => ['stringValue' => $this->setupConfig['environment']]
                                ],
                                [
                                    'key' => 'host.name',
                                    'value' => ['stringValue' => InstrumentationHelper::getHostIdentifier()]
                                ]
                            ]
                        ],
                        'scopeLogs' => [
                            [
                                'scope' => [
                                    'name' => 'vaahsignoz.exception'
                                ],
                                'logRecords' => [
                                    [
                                        'timeUnixNano' => (int)(microtime(true) * 1e9),
                                        'severityNumber' => $this->getSeverityNumber($level),
                                        'severityText' => strtoupper($level),
                                        'body' => [
                                            'stringValue' => $exception->getMessage()
                                        ],
                                        'attributes' => $this->formatExceptionAttributes($exception, $fileInfo, $level)
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            
            // Add request information if available
            if (request()) {
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.url',
                    'value' => ['stringValue' => request()->fullUrl()]
                ];
                
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.method',
                    'value' => ['stringValue' => request()->method()]
                ];
                
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.user_agent',
                    'value' => ['stringValue' => request()->userAgent() ?? 'unknown']
                ];
                
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.client_ip',
                    'value' => ['stringValue' => request()->ip()]
                ];
                
                // Add route information if available
                if (request()->route()) {
                    $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                        'key' => 'http.route',
                        'value' => ['stringValue' => request()->route()->getName() ?? request()->route()->uri()]
                    ];
                    
                    // Add controller and action if available
                    $action = request()->route()->getAction();
                    if (isset($action['controller'])) {
                        $controller = $action['controller'];
                        // Format controller name to include backslashes
                        if (is_string($controller) && strpos($controller, '@') !== false) {
                            $parts = explode('@', $controller);
                            $className = $parts[0];
                            // Ensure backslashes are preserved in the class name
                            $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                                'key' => 'http.controller',
                                'value' => ['stringValue' => $className]
                            ];
                            
                            if (isset($parts[1])) {
                                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                                    'key' => 'http.action',
                                    'value' => ['stringValue' => $parts[1]]
                                ];
                            }
                        } else {
                            $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                                'key' => 'http.controller',
                                'value' => ['stringValue' => $controller]
                            ];
                        }
                    }
                }
            }
            
            // Send to SigNoz logs endpoint
            $logsEndpoint = str_replace('/v1/traces', '/v1/logs', $this->endpoint);
            
            $response = $this->httpClient->post($logsEndpoint, [
                'json' => $logData,
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);
            
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::error('Failed to send exception to SigNoz: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Create a trace for the exception
     */
    protected function createExceptionTrace(Throwable $exception, string $level)
    {
        // Get the tracer
        $tracer = TracerFactory::getTracer();
        
        // Create a new span for the exception
        $exceptionClass = str_replace('\\\\', '\\', get_class($exception));
        $span = $tracer->spanBuilder('exception.' . $exceptionClass)
            ->setAttribute('exception.type', $exceptionClass)
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
                    $controller = $action['controller'];
                    // Format controller name to include backslashes
                    if (is_string($controller) && strpos($controller, '@') !== false) {
                        $parts = explode('@', $controller);
                        $className = $parts[0];
                        $span->setAttribute('http.controller', $className);
                        
                        if (isset($parts[1])) {
                            $span->setAttribute('http.action', $parts[1]);
                        }
                    } else {
                        $span->setAttribute('http.controller', $controller);
                    }
                }
            }
        }
        
        // End the span to send it to SigNoz
        $span->end();
    }
    
    /**
     * Format exception attributes for SigNoz
     */
    protected function formatExceptionAttributes(Throwable $exception, array $fileInfo, string $level)
    {
        $attributes = [];
        
        // Add exception information
        $exceptionClass = get_class($exception);
        $attributes[] = [
            'key' => 'exception.type',
            'value' => ['stringValue' => $exceptionClass]
        ];
        
        $attributes[] = [
            'key' => 'exception.message',
            'value' => ['stringValue' => $exception->getMessage()]
        ];
        
        $attributes[] = [
            'key' => 'exception.stacktrace',
            'value' => ['stringValue' => $exception->getTraceAsString()]
        ];
        
        // Add file information attributes
        if (!empty($fileInfo['file'])) {
            $attributes[] = [
                'key' => 'exception.file',
                'value' => ['stringValue' => $fileInfo['file']]
            ];
        }
        
        if (!empty($fileInfo['line'])) {
            $attributes[] = [
                'key' => 'exception.line',
                'value' => ['intValue' => $fileInfo['line']]
            ];
        }
        
        if (!empty($fileInfo['function'])) {
            $attributes[] = [
                'key' => 'exception.function',
                'value' => ['stringValue' => $fileInfo['function']]
            ];
        }
        
        if (!empty($fileInfo['class'])) {
            // Make sure to preserve the backslashes in the class name
            $className = str_replace('\\\\', '\\', $fileInfo['class']);
            $attributes[] = [
                'key' => 'exception.class',
                'value' => ['stringValue' => $className]
            ];
        }
        
        // Add code context if available
        if (file_exists($exception->getFile())) {
            $fileLines = file($exception->getFile());
            if (isset($fileLines[$exception->getLine() - 1])) {
                $attributes[] = [
                    'key' => 'exception.code_line',
                    'value' => ['stringValue' => trim($fileLines[$exception->getLine() - 1])]
                ];
            }
            
            // Add a few lines before and after for context
            $contextLines = [];
            $startLine = max(0, $exception->getLine() - 3);
            $endLine = min(count($fileLines), $exception->getLine() + 2);
            
            for ($i = $startLine; $i < $endLine; $i++) {
                $contextLines[] = ($i + 1) . ': ' . trim($fileLines[$i]);
            }
            
            $attributes[] = [
                'key' => 'exception.code_context',
                'value' => ['stringValue' => implode("\n", $contextLines)]
            ];
        }
        
        // Add log level information
        $attributes[] = [
            'key' => 'log.level',
            'value' => ['stringValue' => $level]
        ];
        
        $attributes[] = [
            'key' => 'log.severity_number',
            'value' => ['intValue' => $this->getSeverityNumber($level)]
        ];
        
        $attributes[] = [
            'key' => 'log.severity_text',
            'value' => ['stringValue' => strtoupper($level)]
        ];
        
        // Mark this as an exception record
        $attributes[] = [
            'key' => 'exception.record',
            'value' => ['boolValue' => true]
        ];
        
        return $attributes;
    }
    
    /**
     * Convert Laravel log level to OpenTelemetry severity number
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/logs/data-model.md#severity-fields
     */
    protected function getSeverityNumber(string $level): int
    {
        $severityMap = [
            'debug' => 5,      // DEBUG
            'info' => 9,       // INFO
            'notice' => 10,    // INFO2
            'warning' => 13,   // WARN
            'error' => 17,     // ERROR
            'critical' => 18,  // ERROR2
            'alert' => 21,     // FATAL
            'emergency' => 24, // FATAL3
        ];
        
        return $severityMap[$level] ?? 9; // Default to INFO if level not found
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
