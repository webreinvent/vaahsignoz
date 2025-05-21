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
                    'Content-Type' => 'application/json',
                ],
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
            $fileInfo['file'] = $exception->getFile()." | line number ".$exception->getLine();
            $fileInfo['line'] = $exception->getLine();
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
     * Format log context as OTLP attributes
     */
    protected function formatAttributes(array $context, array $fileInfo): array
    {
        $attributes = [];

        // Add file information attributes
        if (!empty($fileInfo['file'])) {
            $attributes[] = [
                'key' => 'log.file',
                'value' => ['stringValue' => $fileInfo['file']]
            ];
        }

        if (!empty($fileInfo['line'])) {
            $attributes[] = [
                'key' => 'log.line',
                'value' => ['intValue' => $fileInfo['line']]
            ];
        }

        if (!empty($fileInfo['function'])) {
            $attributes[] = [
                'key' => 'log.function',
                'value' => ['stringValue' => $fileInfo['function']]
            ];
        }

        if (!empty($fileInfo['class'])) {
            // Ensure class name has proper formatting with backslashes
            $className = str_replace('\\\\', '\\', $fileInfo['class']);
            $attributes[] = [
                'key' => 'log.class',
                'value' => ['stringValue' => $className]
            ];
        }

        // Add context attributes
        foreach ($context as $key => $value) {
            // Skip the exception object as we've already extracted what we need
            if ($key === 'exception' && $value instanceof \Throwable) {
                continue;
            }

            $attributes[] = [
                'key' => $key,
                'value' => ['stringValue' => is_scalar($value) ? (string)$value : json_encode($value)]
            ];
        }

        // Add standard attributes
        $attributes[] = [
            'key' => 'log.logger',
            'value' => ['stringValue' => 'laravel']
        ];

        // Get the log level from the context if available
        $logLevel = $context['level'] ?? 'info';
        
        $attributes[] = [
            'key' => 'log.level',
            'value' => ['stringValue' => $logLevel]
        ];

        $attributes[] = [
            'key' => 'log.severity_number',
            'value' => ['intValue' => $this->getSeverityNumber($logLevel)]
        ];

        $attributes[] = [
            'key' => 'log.severity_text',
            'value' => ['stringValue' => strtoupper((string)$logLevel)]
        ];

        return $attributes;
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
