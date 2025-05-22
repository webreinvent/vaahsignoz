<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

class ClientInstrumentation
{
    /**
     * Configuration for the instrumentation
     */
    protected $vaahSignozConfig;
    
    /**
     * Store active spans for correlation between request and response
     */
    protected static $activeSpans = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->vaahSignozConfig = config('vaahsignoz');
    }

    /**
     * Boot the instrumentation
     */
    public function boot()
    {
        try {
            // Only register event listeners if HTTP client instrumentation is enabled
            if (!isset($this->vaahSignozConfig['instrumentation']['http_client']) || 
                !$this->vaahSignozConfig['instrumentation']['http_client']) {
                return;
            }
            
            Event::listen(RequestSending::class, [$this, 'onRequestSending']);
            Event::listen(ResponseReceived::class, [$this, 'onResponseReceived']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot client instrumentation.', 0, $e);
        }
    }

    /**
     * Handle HTTP client request sending event
     */
    public function onRequestSending(RequestSending $event)
    {
        $request = $event->request;
        $url = $request->url();
        $method = $request->method();
        
        // Extract host and path from URL
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown-host';
        $path = $parsedUrl['path'] ?? '/';
        $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
        
        // Create a more descriptive span name following OpenTelemetry semantic conventions
        // Format: http.client.<method>.<host>
        $spanName = 'http.client.' . strtolower($method) . '.' . $host;
        
        // Prepare attributes for the span
        $attributes = [
            'http.url' => $url,
            'http.method' => $method,
            'http.scheme' => $parsedUrl['scheme'] ?? 'http',
            'http.host' => $host,
            'http.target' => $path,
            'net.peer.name' => $host,
            'net.peer.port' => $port,
            'http.user_agent' => $request->getHeaderLine('User-Agent'),
            'http.request_id' => $request->getHeaderLine('X-Request-ID'),
            'span.kind' => 'client',
        ];
        
        // Add request headers as attributes (excluding sensitive ones)
        foreach ($request->getHeaders() as $name => $values) {
            // Skip sensitive headers
            if (!in_array(strtolower($name), ['authorization', 'cookie', 'x-api-key'])) {
                $attributes['http.request.header.' . strtolower($name)] = implode(', ', $values);
            }
        }
        
        // Use the standardized span creation method
        $span = TracerFactory::createSpan($spanName, $attributes, 'client');
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // Generate a unique request ID for correlation
        $requestId = md5($url . microtime(true));
        $span->setAttribute('http.request.correlation_id', $requestId);
        
        // Store the span for correlation with the response
        self::$activeSpans[$requestId] = $span;
        
        // Inject trace context headers into outgoing HTTP request
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, null, Context::getCurrent());
        foreach ($carrier as $key => $value) {
            $event->request->withHeader($key, $value);
        }
        
        // Add our correlation ID to the request
        $event->request->withHeader('X-Correlation-ID', $requestId);
    }

    /**
     * Handle HTTP client response received event
     */
    public function onResponseReceived(ResponseReceived $event)
    {
        $response = $event->response;
        $request = $event->request;
        $url = $request->url();
        $method = $request->method();
        $statusCode = $response->status();
        
        // Extract host from URL
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown-host';
        
        // Try to find the request span
        $requestId = $request->getHeaderLine('X-Correlation-ID');
        $parentSpan = null;
        
        if ($requestId && isset(self::$activeSpans[$requestId])) {
            $parentSpan = self::$activeSpans[$requestId];
            // Remove from active spans
            unset(self::$activeSpans[$requestId]);
        }
        
        // Create a more descriptive span name following OpenTelemetry semantic conventions
        // Format: http.client.response.<method>.<host>.<status>
        $spanName = 'http.client.response.' . strtolower($method) . '.' . $host . '.' . $statusCode;
        
        // Prepare attributes for the span
        $attributes = [
            'http.url' => $url,
            'http.method' => $method,
            'http.status_code' => $statusCode,
            'span.kind' => 'client',
        ];
        
        // Add response headers as attributes (excluding sensitive ones)
        foreach ($response->headers() as $name => $values) {
            // Skip sensitive headers
            if (!in_array(strtolower($name), ['set-cookie', 'authorization'])) {
                $attributes['http.response.header.' . strtolower($name)] = implode(', ', $values);
            }
        }
        
        // Use the standardized span creation method
        $span = TracerFactory::createSpan($spanName, $attributes, 'client', $parentSpan);
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // Set appropriate status based on HTTP status code
        if ($statusCode >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR, 'Server error');
            $span->setAttribute('http.status_severity', 'error');
        } elseif ($statusCode >= 400) {
            // OpenTelemetry does not have a "warning" status, so use unset and add an attribute
            $span->setStatus(StatusCode::STATUS_UNSET, 'Client warning');
            $span->setAttribute('http.status_severity', 'warning');
        } else {
            $span->setStatus(StatusCode::STATUS_OK, 'OK');
            $span->setAttribute('http.status_severity', 'ok');
        }
        
        // Add response body for error responses (truncated to avoid huge spans)
        if ($statusCode >= 400) {
            $responseBody = (string) $response->getBody();
            if (strlen($responseBody) > 1000) {
                $responseBody = substr($responseBody, 0, 1000) . '... (truncated)';
            }
            $span->setAttribute('http.response.body', $responseBody);
        }
        
        // End the span to ensure it's sent to SignOz
        $span->end();
        
        // If we have a parent span, end it too
        if ($parentSpan) {
            $parentSpan->end();
        }
    }
    
    /**
     * Add standard attributes to a span
     */
    protected function addStandardAttributes($span)
    {
        $span->setAttribute('service.name', $this->vaahSignozConfig['otel']['service_name'] ?? 'laravel-app');
        $span->setAttribute('service.version', $this->vaahSignozConfig['otel']['version'] ?? '1.0.0');
        $span->setAttribute('deployment.environment', $this->vaahSignozConfig['otel']['environment'] ?? 'production');
        $span->setAttribute('host.name', InstrumentationHelper::getHostIdentifier());
        
        // Add current trace and span IDs for correlation
        $traceId = InstrumentationHelper::getCurrentTraceId();
        $spanId = InstrumentationHelper::getCurrentSpanId();
        
        if ($traceId) {
            $span->setAttribute('trace_id', $traceId);
            
            if ($spanId) {
                $span->setAttribute('span_id', $spanId);
            }
        }
    }
}
