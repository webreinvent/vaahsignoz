<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;
use GuzzleHttp\Client;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use Illuminate\Http\Request;

class LogInstrumentation
{
    protected $vaahSignozConfig;
    protected $httpClient;
    protected $endpoint;
    protected $serviceName;

    public function boot()
    {
        $this->vaahSignozConfig = config('vaahsignoz');
        $this->endpoint = rtrim($this->vaahSignozConfig['otel']['endpoint_logs'] ?? 'http://localhost:4318/v1/logs', '/');

        // Ensure proper OTLP endpoint format
        if (parse_url($this->endpoint, PHP_URL_PATH) === null || parse_url($this->endpoint, PHP_URL_PATH) === '') {
            $this->endpoint .= '/v1/logs';
        }

        $this->serviceName = $this->vaahSignozConfig['otel']['service_name'] ?? 'laravel-app';
        $this->httpClient = new Client(['timeout' => 3.0, 'verify' => false]);

        // Listen for Laravel log events
        Log::listen(function (MessageLogged $event) {
            try {
                $this->sendLogToSigNoz($event);
            } catch (\Throwable $e) {
                // Prevent logging loops
                if (config('app.debug')) {
                    error_log("SigNoz logging error: " . $e->getMessage());
                }
            }
        });
    }

    /**
     * Send log to SigNoz using OTLP format
     */
    protected function sendLogToSigNoz(MessageLogged $event)
    {
        // Extract file and line information from exception or backtrace
        $fileInfo = $this->extractFileInfo($event);

        // Standard OpenTelemetry log format
        $logData = [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $this->vaahSignozConfig['otel']['service_name']]
                            ],
                            [
                                'key' => 'service.version',
                                'value' => ['stringValue' => $this->vaahSignozConfig['otel']['version']]
                            ],
                            [
                                'key' => 'deployment.environment',
                                'value' => ['stringValue' => $this->vaahSignozConfig['otel']['environment']]
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
                                'name' => 'laravel.logs',
                                'version' => $this->vaahSignozConfig['otel']['version']
                            ],
                            'logRecords' => [
                                [
                                    'timeUnixNano' => (int)(microtime(true) * 1000000000),
                                    'observedTimeUnixNano' => (int)(microtime(true) * 1000000000),
                                    'severityNumber' => $this->getSeverityNumber($event->level),
                                    'severityText' => strtoupper($event->level),
                                    'body' => ['stringValue' => $event->message],
                                    'attributes' => $this->formatAttributes($event->context, $fileInfo)
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // Get current trace and span IDs if available
        $traceId = \WebReinvent\VaahSignoz\Helpers\InstrumentationHelper::getCurrentTraceId();
        $spanId = \WebReinvent\VaahSignoz\Helpers\InstrumentationHelper::getCurrentSpanId();
        
        // Add trace context to the log record if available - this is critical for correlation
        if ($traceId) {
            // Add trace_id directly to the log record according to OpenTelemetry spec
            // Note: SignOz expects trace_id and span_id as attributes for proper correlation
            $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                'key' => 'trace_id',
                'value' => ['stringValue' => $traceId]
            ];
            
            if ($spanId) {
                // Add span_id directly to the log record according to OpenTelemetry spec
                $logData['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][] = [
                    'key' => 'span_id',
                    'value' => ['stringValue' => $spanId]
                ];
            }
        }

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

        try {
            $response = $this->httpClient->post($this->endpoint, [
                'json' => $logData,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Exception $e) {
            // Silent failure to prevent logging loops
            return false;
        }
    }

    /**
     * Extract file and line information from the event
     */
    protected function extractFileInfo(MessageLogged $event): array
    {
        $fileInfo = [
            'file' => null,
            'line' => null,
            'function' => null,
            'class' => null,
        ];

        // Check if there's an exception in the context
        if (isset($event->context['exception']) && $event->context['exception'] instanceof \Throwable) {
            $exception = $event->context['exception'];
            $fileInfo['file'] = $exception->getFile();
            $fileInfo['line'] = $exception->getLine();
            
            // Generate and store exception ID if not already set
            if (!\WebReinvent\VaahSignoz\Helpers\InstrumentationHelper::getCurrentExceptionId()) {
                $exceptionId = md5(get_class($exception) . $exception->getMessage() . $exception->getFile() . $exception->getLine() . microtime(true));
                \WebReinvent\VaahSignoz\Helpers\InstrumentationHelper::setCurrentExceptionId($exceptionId);
            }
        }
        // Otherwise try to get information from the backtrace
        else {
            // Get backtrace and look for the first non-vendor, non-framework call
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

            foreach ($backtrace as $trace) {
                // Skip vendor and framework files
                $file = $trace['file'] ?? '';
                if (empty($file) || strpos($file, '/vendor/') !== false || strpos($file, '/framework/') !== false) {
                    continue;
                }
                
                $fileInfo['file'] = $file;
                $fileInfo['line'] = $trace['line'] ?? null;
                $fileInfo['function'] = $trace['function'] ?? null;
                $fileInfo['class'] = $trace['class'] ?? null;
                break;
            }
        }

        return $fileInfo;
    }

    /**
     * Format attributes for the log record
     * 
     * @param array $context
     * @param array $fileInfo
     * @return array
     */
    protected function formatAttributes($context, $fileInfo)
    {
        $attributes = [];
        
        // Add file information
        if (isset($fileInfo['file'])) {
            $attributes[] = [
                'key' => 'log.file.name',
                'value' => ['stringValue' => $fileInfo['file']]
            ];
        }
        
        if (isset($fileInfo['line'])) {
            $attributes[] = [
                'key' => 'log.file.line',
                'value' => ['intValue' => $fileInfo['line']]
            ];
        }
        
        if (isset($fileInfo['function'])) {
            $attributes[] = [
                'key' => 'log.function',
                'value' => ['stringValue' => $fileInfo['function']]
            ];
        }
        
        if (isset($fileInfo['class'])) {
            $attributes[] = [
                'key' => 'log.class',
                'value' => ['stringValue' => $fileInfo['class']]
            ];
        }
        
        // Add exception information if available
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            
            $attributes[] = [
                'key' => 'exception.type',
                'value' => ['stringValue' => get_class($exception)]
            ];
            
            $attributes[] = [
                'key' => 'exception.message',
                'value' => ['stringValue' => $exception->getMessage()]
            ];
            
            $attributes[] = [
                'key' => 'exception.stacktrace',
                'value' => ['stringValue' => $exception->getTraceAsString()]
            ];
            
            $attributes[] = [
                'key' => 'exception.file',
                'value' => ['stringValue' => $exception->getFile()]
            ];
            
            $attributes[] = [
                'key' => 'exception.line',
                'value' => ['intValue' => $exception->getLine()]
            ];
            
            // Get the exception ID for correlation
            $exceptionId = \WebReinvent\VaahSignoz\Helpers\InstrumentationHelper::getCurrentExceptionId();
            if ($exceptionId) {
                $attributes[] = [
                    'key' => 'exception.id',
                    'value' => ['stringValue' => $exceptionId]
                ];
                
                $attributes[] = [
                    'key' => 'log.correlation_id',
                    'value' => ['stringValue' => $exceptionId]
                ];
            }
        }
        
        // Add all context values as attributes
        foreach ($context as $key => $value) {
            // Skip the exception object as we've already processed it
            if ($key === 'exception' && $value instanceof \Throwable) {
                continue;
            }
            
            // Format the value based on its type
            $formattedValue = $this->formatAttributeValue($key, $value);
            if ($formattedValue) {
                $attributes[] = $formattedValue;
            }
        }
        
        // Add request information if available
        if (request()) {
            $attributes[] = [
                'key' => 'http.method',
                'value' => ['stringValue' => request()->method()]
            ];
            
            $attributes[] = [
                'key' => 'http.url',
                'value' => ['stringValue' => request()->fullUrl()]
            ];
            
            $attributes[] = [
                'key' => 'http.target',
                'value' => ['stringValue' => request()->path()]
            ];
            
            if (request()->route()) {
                $routeName = request()->route()->getName();
                if ($routeName) {
                    $attributes[] = [
                        'key' => 'http.route',
                        'value' => ['stringValue' => $routeName]
                    ];
                }
            }
            
            $attributes[] = [
                'key' => 'http.user_agent',
                'value' => ['stringValue' => request()->userAgent() ?? '']
            ];
            
            $attributes[] = [
                'key' => 'http.client_ip',
                'value' => ['stringValue' => request()->ip()]
            ];
        }
        
        // Add user information if available
        if (auth()->check()) {
            $user = auth()->user();
            
            $attributes[] = [
                'key' => 'user.id',
                'value' => ['stringValue' => (string) $user->id]
            ];
            
            if (isset($user->email)) {
                $attributes[] = [
                    'key' => 'user.email',
                    'value' => ['stringValue' => $user->email]
                ];
            }
            
            if (isset($user->name)) {
                $attributes[] = [
                    'key' => 'user.name',
                    'value' => ['stringValue' => $user->name]
                ];
            }
        }
        
        return $attributes;
    }
    
    /**
     * Format a context value as an attribute for OpenTelemetry
     * 
     * @param string $key
     * @param mixed $value
     * @return array|null
     */
    protected function formatAttributeValue($key, $value)
    {
        // Skip null values
        if ($value === null) {
            return null;
        }
        
        // Format based on value type
        if (is_string($value)) {
            return [
                'key' => $key,
                'value' => ['stringValue' => $value]
            ];
        } elseif (is_int($value)) {
            return [
                'key' => $key,
                'value' => ['intValue' => $value]
            ];
        } elseif (is_float($value)) {
            return [
                'key' => $key,
                'value' => ['doubleValue' => $value]
            ];
        } elseif (is_bool($value)) {
            return [
                'key' => $key,
                'value' => ['boolValue' => $value]
            ];
        } elseif (is_array($value)) {
            // Convert array to JSON string
            return [
                'key' => $key,
                'value' => ['stringValue' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            ];
        } elseif (is_object($value)) {
            // Handle objects
            if (method_exists($value, '__toString')) {
                return [
                    'key' => $key,
                    'value' => ['stringValue' => (string) $value]
                ];
            } else {
                // Convert object to JSON string
                return [
                    'key' => $key,
                    'value' => ['stringValue' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
                ];
            }
        }
        
        // For any other type, convert to string
        return [
            'key' => $key,
            'value' => ['stringValue' => (string) $value]
        ];
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

    /**
     * Convert Laravel log level to OpenTelemetry severity number
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/logs/data-model.md#severity-fields
     */
    protected function getSeverityNumber($level)
    {
        // Default to INFO if level is null or empty
        if (empty($level)) {
            return 9; // INFO
        }
        
        $map = [
            'debug' => 5,     // DEBUG
            'info' => 9,      // INFO
            'notice' => 13,   // INFO2
            'warning' => 17,  // WARN
            'error' => 21,    // ERROR
            'critical' => 25, // ERROR2
            'alert' => 29,    // ERROR3
            'emergency' => 33 // FATAL
        ];

        return $map[strtolower((string)$level)] ?? 9; // Default to INFO if unknown
    }
}
