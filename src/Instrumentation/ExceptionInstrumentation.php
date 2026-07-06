<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use Throwable;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;

class ExceptionInstrumentation
{
    protected $setupConfig;
    protected $app;
    protected $endpoint;

    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.exception', true)) {
            return;
        }

        $this->setupConfig = TracerFactory::getSetupConfig();
        $this->app = app();
        $this->endpoint = $this->setupConfig['endpoint'];

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

                    public function __construct($originalHandler, $instrumentationClass)
                    {
                        $this->originalHandler = $originalHandler;
                        $this->instrumentationClass = $instrumentationClass;
                    }

                    public function report(Throwable $e)
                    {
                        // Let Sentry (and other handlers) capture first — signoz
                        // runs after as a lightweight observer to avoid competing
                        // for memory (both reading files, stack traces, etc.)
                        $this->originalHandler->report($e);
                        $this->instrumentationClass->handleException($e, 'error', true);
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
        $previousHandler = set_exception_handler(function (Throwable $exception) use (&$previousHandler) {
            try {
                $this->handleException($exception, 'critical');
            } catch (\Throwable $e) {
                if (config('app.debug')) {
                    error_log('SigNoz exception handler error: ' . $e->getMessage());
                }
            }

            if (is_callable($previousHandler)) {
                call_user_func($previousHandler, $exception);
            }
        });
    }

    /**
     * Handle the exception by creating a span and logging it to SigNoz
     */
    public function handleException(Throwable $exception, string $level, bool $skipHeavy = false)
    {
        try {
            $statusCode = method_exists($exception, 'getStatusCode')
                ? $exception->getStatusCode()
                : (property_exists($exception, 'status') && is_numeric($exception->status)
                    ? (int) $exception->status
                    : (isset($exception->statusCode) && is_numeric($exception->statusCode)
                        ? (int) $exception->statusCode
                        : 500));

            $exceptionId = md5(get_class($exception) . $exception->getMessage()
                . $exception->getFile() . $exception->getLine() . microtime(true));

            InstrumentationHelper::setCurrentExceptionId($exceptionId);

            // Send as log
            $this->sendExceptionToSigNoz($exception, $level, $statusCode, $exceptionId, $skipHeavy);

            // Record on active span
            $this->recordExceptionOnActiveSpan($exception, $level, $statusCode, $exceptionId, $skipHeavy);

        } catch (\Throwable $e) {
            if (config('app.debug')) {
                error_log('Failed to handle exception in SigNoz: ' . $e->getMessage());
            }
        }
    }

    /**
     * Record exception on the current active span
     */
    protected function recordExceptionOnActiveSpan(Throwable $exception, string $level, int $statusCode, string $exceptionId, bool $skipHeavy = false)
    {
        $tracer = TracerFactory::getTracer();
        $currentSpan = TracerFactory::getCurrentSpan();

        if (!$currentSpan) {
            $shortClass = class_basename(get_class($exception));
            $spanName = $statusCode >= 400 ? 'http.error.' . $statusCode : 'exception.' . $shortClass;
            $currentSpan = $tracer->spanBuilder($spanName)->startSpan();
            $scope = $currentSpan->activate();
        }

        try {
            // Core exception attributes
            $currentSpan->setAttribute('exception.file', $exception->getFile());
            $currentSpan->setAttribute('exception.line', $exception->getLine());
            $currentSpan->setAttribute('log.level', $level);
            $currentSpan->setAttribute('log.severity_number', $this->getSeverityNumber($level));
            $currentSpan->setAttribute('log.severity_text', strtoupper($level));
            $currentSpan->setAttribute('http.status_code', $statusCode);

            // Code context (skip if another tool like Sentry already captured it)
            if (!$skipHeavy && file_exists($exception->getFile())) {
                $line = $this->readLine($exception->getFile(), $exception->getLine());
                if ($line !== null) {
                    $currentSpan->setAttribute('exception.code_line', trim($line));
                }
            }

            // User info (with PII masking)
            if (auth()->check()) {
                $user = auth()->user();
                $currentSpan->setAttribute('user.id', (string) $user->id);

                if (config('vaahsignoz.security.pii_mask', false)) {
                    $currentSpan->setAttribute('user.email', hash('sha256', $user->email ?? ''));
                    $currentSpan->setAttribute('user.name', hash('sha256', $user->name ?? ''));
                } else {
                    $currentSpan->setAttribute('user.email', $user->email ?? '');
                    $currentSpan->setAttribute('user.name', $user->name ?? '');
                }
            }

            // Request info (path only, no full URL with query params)
            if (request()) {
                $currentSpan->setAttribute('http.method', request()->method());
                $currentSpan->setAttribute('http.target', request()->path());
                $currentSpan->setAttribute('http.client_ip', request()->ip());

                if (request()->route()) {
                    $routeName = request()->route()->getName();
                    if ($routeName) {
                        $currentSpan->setAttribute('http.route', $routeName);
                    }
                }
            }

            if ($exceptionId) {
                $currentSpan->setAttribute('exception.id', $exceptionId);
                $currentSpan->setAttribute('log.correlation_id', $exceptionId);
            }

            // Record exception for SigNoz Exceptions section
            $currentSpan->recordException($exception);
            InstrumentationHelper::setSpanStatus($currentSpan, 'error', $exception->getMessage());

            if (!TracerFactory::getCurrentSpan()) {
                $currentSpan->end();
                if (isset($scope)) {
                    $scope->detach();
                }
            }

        } catch (\Throwable $e) {
            if (config('app.debug')) {
                error_log('Failed to record exception on span: ' . $e->getMessage());
            }
            if (!TracerFactory::getCurrentSpan() && isset($currentSpan)) {
                $currentSpan->end();
                if (isset($scope)) {
                    $scope->detach();
                }
            }
        }
    }

    /**
     * Send the exception as a log with special exception attributes
     */
    protected function sendExceptionToSigNoz(Throwable $exception, string $level, int $statusCode, string $exceptionId, bool $skipHeavy = false)
    {
        try {
            $traceId = InstrumentationHelper::getCurrentTraceId();
            $spanId = InstrumentationHelper::getCurrentSpanId();

            $logData = [
                'resourceLogs' => [
                    [
                        'resource' => [
                            'attributes' => [
                                ['key' => 'service.name', 'value' => ['stringValue' => $this->setupConfig['serviceName']]],
                                ['key' => 'service.version', 'value' => ['stringValue' => $this->setupConfig['version'] ?? '0.0.0']],
                                ['key' => 'deployment.environment', 'value' => ['stringValue' => $this->setupConfig['environment']]],
                                ['key' => 'host.name', 'value' => ['stringValue' => InstrumentationHelper::getHostIdentifier()]],
                            ]
                        ],
                        'scopeLogs' => [
                            [
                                'scope' => ['name' => 'vaahsignoz.exception'],
                                'logRecords' => [
                                    [
                                        'timeUnixNano' => (int) (microtime(true) * 1e9),
                                        'severityNumber' => $this->getSeverityNumber($level),
                                        'severityText' => strtoupper($level),
                                        'body' => ['stringValue' => $exception->getMessage()],
                                        'attributes' => $this->formatExceptionAttributes($exception, $level, $statusCode, $exceptionId, $skipHeavy),
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // Trace context
            if ($traceId) {
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'trace_id',
                    'value' => ['stringValue' => $traceId]
                ];
                if ($spanId) {
                    $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                        'key' => 'span_id',
                        'value' => ['stringValue' => $spanId]
                    ];
                }
            }

            // Request info (path only, no full URL)
            if (request()) {
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.target',
                    'value' => ['stringValue' => request()->path()]
                ];
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.method',
                    'value' => ['stringValue' => request()->method()]
                ];
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'http.client_ip',
                    'value' => ['stringValue' => request()->ip()]
                ];

                if (request()->route()) {
                    $routeName = request()->route()->getName();
                    if ($routeName) {
                        $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                            'key' => 'http.route',
                            'value' => ['stringValue' => $routeName]
                        ];
                    }
                }
            }

            // Use shared HTTP client from TracerFactory
            $logsEndpoint = str_replace('/v1/traces', '/v1/logs', $this->endpoint);
            $client = TracerFactory::getSharedClient();

            $client->post($logsEndpoint, [
                'json' => $logData,
                'headers' => ['Content-Type' => 'application/json']
            ]);

        } catch (\Throwable $e) {
            if (config('app.debug')) {
                error_log('Failed to send exception to SigNoz: ' . $e->getMessage());
            }
        }
    }

    /**
     * Format exception attributes for SigNoz
     */
    protected function formatExceptionAttributes(Throwable $exception, string $level, int $statusCode, string $exceptionId, bool $skipHeavy = false): array
    {
        $attributes = [];

        $attributes[] = ['key' => 'exception.type', 'value' => ['stringValue' => get_class($exception)]];
        $attributes[] = ['key' => 'exception.message', 'value' => ['stringValue' => $exception->getMessage()]];
        $attributes[] = ['key' => 'exception.file', 'value' => ['stringValue' => $exception->getFile()]];
        $attributes[] = ['key' => 'exception.line', 'value' => ['intValue' => $exception->getLine()]];
        $attributes[] = ['key' => 'exception.stacktrace', 'value' => ['stringValue' => $exception->getTraceAsString()]];

        $attributes[] = ['key' => 'log.level', 'value' => ['stringValue' => $level]];
        $attributes[] = ['key' => 'log.severity_number', 'value' => ['intValue' => $this->getSeverityNumber($level)]];
        $attributes[] = ['key' => 'log.severity_text', 'value' => ['stringValue' => strtoupper($level)]];
        $attributes[] = ['key' => 'http.status_code', 'value' => ['intValue' => $statusCode]];
        $attributes[] = ['key' => 'exception.record', 'value' => ['boolValue' => true]];

        // Code context (skip when heavy mode is off to save memory)
        if (!$skipHeavy && file_exists($exception->getFile())) {
            $line = $this->readLine($exception->getFile(), $exception->getLine());
            if ($line !== null) {
                $attributes[] = ['key' => 'exception.code_line', 'value' => ['stringValue' => trim($line)]];
            }

            $contextLines = $this->readLines($exception->getFile(), max(1, $exception->getLine() - 3), $exception->getLine() + 1);
            if (!empty($contextLines)) {
                $attributes[] = ['key' => 'exception.code_context', 'value' => ['stringValue' => implode("\n", $contextLines)]];
            }
        }

        // User info (with PII masking)
        if (auth()->check()) {
            $user = auth()->user();
            $attributes[] = ['key' => 'user.id', 'value' => ['stringValue' => (string) $user->id]];

            if (config('vaahsignoz.security.pii_mask', false)) {
                $attributes[] = ['key' => 'user.email', 'value' => ['stringValue' => hash('sha256', $user->email ?? '')]];
                $attributes[] = ['key' => 'user.name', 'value' => ['stringValue' => hash('sha256', $user->name ?? '')]];
            } else {
                $attributes[] = ['key' => 'user.email', 'value' => ['stringValue' => $user->email ?? '']];
                $attributes[] = ['key' => 'user.name', 'value' => ['stringValue' => $user->name ?? '']];
            }
        }

        // Exception ID for correlation
        $attributes[] = ['key' => 'exception.id', 'value' => ['stringValue' => $exceptionId]];
        $attributes[] = ['key' => 'log.correlation_id', 'value' => ['stringValue' => $exceptionId]];

        return $attributes;
    }

    /**
     * Convert Laravel log level to OpenTelemetry severity number
     */
    protected function getSeverityNumber(string $level): int
    {
        $severityMap = [
            'debug' => 5,
            'info' => 9,
            'notice' => 10,
            'warning' => 13,
            'error' => 17,
            'critical' => 18,
            'alert' => 21,
            'emergency' => 24,
        ];

        return $severityMap[$level] ?? 9;
    }

    /**
     * Read a single line from a file without loading the entire file.
     * Memory-efficient alternative to file().
     */
    protected function readLine(string $file, int $lineNumber): ?string
    {
        try {
            $fh = fopen($file, 'r');
            if (!$fh) {
                return null;
            }
            for ($i = 1; $i < $lineNumber && false !== fgets($fh); $i++);
            $line = fgets($fh);
            fclose($fh);
            return $line !== false ? $line : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read a range of lines from a file without loading the entire file.
     * Returns an array of "line_number: line_content" strings.
     * Memory-efficient alternative to file().
     */
    protected function readLines(string $file, int $startLine, int $endLine): array
    {
        $lines = [];
        try {
            $fh = fopen($file, 'r');
            if (!$fh) {
                return [];
            }
            $i = 1;
            while ($i <= $endLine && false !== ($line = fgets($fh))) {
                if ($i >= $startLine) {
                    $lines[] = $i . ': ' . trim($line);
                }
                $i++;
            }
            fclose($fh);
        } catch (\Throwable $e) {
            // Fail silently
        }
        return $lines;
    }
}
