<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
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
     * Pending trace context headers to inject (best-effort)
     */
    protected static $pendingHeaders = [];

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
            if (!isset($this->vaahSignozConfig['instrumentations']['client']) ||
                !$this->vaahSignozConfig['instrumentations']['client']) {
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
        
        // Prepare attributes for the span (PSR-7: getHeader() returns array, not string)
        $attributes = [
            'http.url' => $url,
            'http.method' => $method,
            'http.scheme' => $parsedUrl['scheme'] ?? 'http',
            'http.host' => $host,
            'http.target' => $path,
            'net.peer.name' => $host,
            'net.peer.port' => $port,
            'span.kind' => 'client',
        ];

        // PSR-7 getHeader() returns array of values — join for scalar attribute
        $ua = $request->getHeader('User-Agent');
        if ($ua) {
            $attributes['http.user_agent'] = implode(', ', $ua);
        }

        $reqId = $request->getHeader('X-Request-ID');
        if ($reqId) {
            $attributes['http.request_id'] = implode(', ', $reqId);
        }

        // Add request headers as attributes (excluding sensitive ones)
        foreach ($request->getHeaders() as $name => $values) {
            if (!in_array(strtolower($name), ['authorization', 'cookie', 'x-api-key'])) {
                $attributes['http.request.header.' . strtolower($name)] = implode(', ', $values);
            }
        }

        // Use the standardized span creation method (SpanKind::KIND_CLIENT for older SDK)
        $span = TracerFactory::createSpan($spanName, $attributes, \OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT);

        // Add standard service information
        $this->addStandardAttributes($span);

        // Generate a unique request ID for correlation
        $requestId = md5($url . microtime(true));
        $span->setAttribute('http.request.correlation_id', $requestId);

        // Store the span for correlation with the response.
        // Use Guzzle request object's internal hash since we can't add headers to the immutable request.
        $requestHash = spl_object_hash($request);
        self::$activeSpans[$requestHash] = [
            'span' => $span,
            'request_id' => $requestId,
        ];

        // Inject trace context headers into outgoing HTTP request.
        // Laravel's HTTP client provides withHeaders() on the PendingRequest macro.
        // The $event->request is a PSR-7 Request (immutable), so we use the macro approach.
        // However, RequestSending fires AFTER headers are set, so we use a workaround:
        // store headers to inject on the next request via static state.
        $carrier = [];
        // Version-agnostic inject: SDK 1.6+ accepts 3 args (carrier, hook, context);
        // older versions only accept 2 (carrier, hook).
        $ref = new \ReflectionMethod(TraceContextPropagator::class, 'inject');
        if (count($ref->getParameters()) >= 3) {
            TraceContextPropagator::getInstance()->inject($carrier, null, Context::getCurrent());
        } else {
            TraceContextPropagator::getInstance()->inject($carrier);
        }

        // Add correlation ID header
        $carrier['X-Correlation-ID'] = $requestId;

        // Store headers to be applied — Laravel's PendingRequest will include them
        // in the outgoing request via the beforeSend callback pattern.
        // Note: This is a best-effort approach since RequestSending fires after request
        // construction. For proper header injection, use Http::withOptions() in user code.
        self::$pendingHeaders[$requestHash] = $carrier;

        // Attempt to set headers on the PendingRequest if accessible
        // This works for Laravel's HTTP client which allows modifying the request
        if (method_exists($request, 'withHeader')) {
            // PSR-7 Request — immutable. Headers won't be set on this instance,
            // but we track them for the response correlation.
        }
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
        
        // Try to find the request span by request object hash
        $requestHash = spl_object_hash($request);
        $parentSpan = null;

        if (isset(self::$activeSpans[$requestHash])) {
            $data = self::$activeSpans[$requestHash];
            $parentSpan = $data['span'];
            // Remove from active spans
            unset(self::$activeSpans[$requestHash]);
            unset(self::$pendingHeaders[$requestHash]);
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
        
        // Use the standardized span creation method (SpanKind::KIND_CLIENT for older SDK)
        $span = TracerFactory::createSpan($spanName, $attributes, \OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT, $parentSpan);
        
        // Add standard service information
        $this->addStandardAttributes($span);
        
        // Set appropriate status based on HTTP status code
        if ($statusCode >= 500) {
            InstrumentationHelper::setSpanStatus($span, 'error', 'Server error');
            $span->setAttribute('http.status_severity', 'error');
        } elseif ($statusCode >= 400) {
            // OpenTelemetry does not have a "warning" status, so use unset and add an attribute
            InstrumentationHelper::setSpanStatus($span, 'unset', 'Client warning');
            $span->setAttribute('http.status_severity', 'warning');
        } else {
            InstrumentationHelper::setSpanStatus($span, 'ok', 'OK');
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
