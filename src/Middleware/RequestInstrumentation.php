<?php

namespace WebReinvent\VaahSignoz\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;
use Symfony\Component\HttpFoundation\Response;

class RequestInstrumentation
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $setupConfig = TracerFactory::getSetupConfig();
        $tracer = TracerFactory::getTracer();

        // Build a more descriptive span name
        $spanName = $this->buildSpanName($request);
        
        // Start the span with enhanced attributes
        $span = $tracer->spanBuilder($spanName)
            ->setAttribute('http.method', $request->method())
            ->setAttribute('http.url', $request->fullUrl())
            ->setAttribute('http.scheme', $request->getScheme())
            ->setAttribute('http.host', $request->getHost())
            ->setAttribute('http.target', $request->getRequestUri())
            ->setAttribute('http.user_agent', $request->userAgent() ?? 'unknown')
            ->setAttribute('http.client_ip', $request->ip())
            ->setAttribute('http.request_content_length', $request->header('Content-Length') ?? 0)
            ->setAttribute('http.request_content_type', $request->header('Content-Type') ?? 'unknown')
            ->setAttribute('service.name', $setupConfig['serviceName'])
            ->setAttribute('service.version', $setupConfig['version'])
            ->setAttribute('deployment.environment', $setupConfig['environment'])
            ->setAttribute('host.name', InstrumentationHelper::getHostIdentifier($request))
            ->startSpan();
            
        // Add route information if available
        if ($request->route()) {
            $routeName = $request->route()->getName();
            if ($routeName) {
                $span->setAttribute('http.route', $routeName);
            } else {
                $span->setAttribute('http.route', $request->route()->uri());
            }
            
            // Add controller and action information
            $action = $request->route()->getAction();
            if (isset($action['controller'])) {
                $controller = $action['controller'];
                // Format controller name to include backslashes
                if (is_string($controller) && strpos($controller, '@') !== false) {
                    $parts = explode('@', $controller);
                    $className = str_replace('\\\\', '\\', $parts[0]);
                    $span->setAttribute('http.controller', $className);
                    
                    if (isset($parts[1])) {
                        $span->setAttribute('http.action', $parts[1]);
                    }
                } else {
                    $span->setAttribute('http.controller', $controller);
                }
            }
            
            // Add route parameters (excluding sensitive data)
            $routeParams = $request->route()->parameters();
            if (!empty($routeParams)) {
                $safeParams = $this->sanitizeParameters($routeParams);
                if (!empty($safeParams)) {
                    $span->setAttribute('http.route_params', json_encode($safeParams));
                }
            }
        }
        
        // Add request input data (excluding sensitive data)
        $inputData = $request->except(['password', 'password_confirmation', 'token', 'api_token', 'secret']);
        if (!empty($inputData)) {
            // Limit the size to avoid huge payloads
            $jsonInput = json_encode($this->truncateValues($inputData));
            if (strlen($jsonInput) <= 2000) {
                $span->setAttribute('http.request_data', $jsonInput);
            } else {
                $span->setAttribute('http.request_data', substr($jsonInput, 0, 1997) . '...');
            }
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            // Add response attributes
            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->setAttribute('http.status_text', $this->getStatusText($response->getStatusCode()));
            $span->setAttribute('http.response_content_length', $response->headers->get('Content-Length') ?? 0);
            $span->setAttribute('http.response_content_type', $response->headers->get('Content-Type') ?? 'unknown');
            
            // Add response time
            if (defined('LARAVEL_START')) {
                $span->setAttribute('http.response_time_ms', round((microtime(true) - LARAVEL_START) * 1000, 2));
            }
            
            // Add memory usage
            $span->setAttribute('process.memory_usage_bytes', memory_get_usage(true));
            $span->setAttribute('process.peak_memory_usage_bytes', memory_get_peak_usage(true));

            return $response;
        } catch (\Throwable $e) {
            // Enhanced exception attributes
            $span->setAttribute('exception.type', str_replace('\\\\', '\\', get_class($e)));
            $span->setAttribute('exception.message', $e->getMessage());
            $span->setAttribute('exception.stacktrace', $e->getTraceAsString());
            $span->setAttribute('exception.file', $e->getFile());
            $span->setAttribute('exception.line', $e->getLine());
            
            // Add HTTP status if it's an HTTP exception
            if (method_exists($e, 'getStatusCode')) {
                $statusCode = $e->getStatusCode();
                $span->setAttribute('http.status_code', $statusCode);
                $span->setAttribute('http.status_text', $this->getStatusText($statusCode));
            } else {
                $span->setAttribute('http.status_code', 500);
                $span->setAttribute('http.status_text', 'Internal Server Error');
            }
            
            if (class_exists(StatusCode::class)) {
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            }
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Build a descriptive span name from the request
     *
     * @param Request $request
     * @return string
     */
    protected function buildSpanName(Request $request)
    {
        $method = $request->method();
        
        // If we have a route, use that for a more descriptive name
        if ($request->route()) {
            $routeName = $request->route()->getName();
            if ($routeName) {
                return $method . ' ' . $routeName;
            }
            
            // Use controller and action if available
            $action = $request->route()->getAction();
            if (isset($action['controller']) && is_string($action['controller'])) {
                if (strpos($action['controller'], '@') !== false) {
                    return $method . ' ' . $action['controller'];
                }
            }
            
            // Fall back to URI pattern
            return $method . ' ' . $request->route()->uri();
        }
        
        // No route, use path
        return $method . ' ' . $request->path();
    }
    
    /**
     * Sanitize parameters to remove sensitive data
     *
     * @param array $params
     * @return array
     */
    protected function sanitizeParameters(array $params)
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'api_key', 'auth'];
        $result = [];
        
        foreach ($params as $key => $value) {
            // Skip sensitive data
            if (in_array(strtolower($key), $sensitiveKeys) || strpos(strtolower($key), 'password') !== false) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            
            // Handle nested arrays
            if (is_array($value)) {
                $result[$key] = $this->sanitizeParameters($value);
                continue;
            }
            
            // Handle scalar values
            if (is_scalar($value) || is_null($value)) {
                $result[$key] = $value;
            } else {
                // Convert objects to string representation
                $result[$key] = '[' . (is_object($value) ? get_class($value) : gettype($value)) . ']';
            }
        }
        
        return $result;
    }
    
    /**
     * Truncate values to avoid huge payloads
     *
     * @param array $data
     * @return array
     */
    protected function truncateValues(array $data)
    {
        $result = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->truncateValues($value);
            } elseif (is_string($value) && strlen($value) > 200) {
                $result[$key] = substr($value, 0, 197) . '...';
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Get HTTP status text for a given status code
     */
    protected function getStatusText(int $code): string
    {
        $statusTexts = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            103 => 'Early Hints',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            208 => 'Already Reported',
            226 => 'IM Used',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Payload Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            418 => 'I\'m a teapot',
            421 => 'Misdirected Request',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            425 => 'Too Early',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
        ];
        
        return $statusTexts[$code] ?? 'Unknown Status';
    }

    /**
     * Get the host identifier - prefer domain name over hostname
     *
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected function getHostIdentifier($request)
    {
        return InstrumentationHelper::getHostIdentifier($request);
    }
}
