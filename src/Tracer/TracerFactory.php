<?php

namespace WebReinvent\VaahSignoz\Tracer;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransport;
use GuzzleHttp\Client;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;

use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;

class TracerFactory
{
    protected static $tracer = null;
    protected static $currentSpan = null;

    public static function getSetupConfig()
    {
        $configVaahSignoz = config('vaahsignoz.otel');
        $setup['endpoint'] = $configVaahSignoz['endpoint'] ?? 'http://localhost:4318/v1/traces';
        $setup['serviceName'] = $configVaahSignoz['service_name'] ?? 'laravel-app';
        $setup['version'] = config('app.version') ?? $configVaahSignoz['version'] ?? '0.0.0';
        $setup['environment'] = $configVaahSignoz['environment'] ?? 'local';

        return $setup;
    }

    public static function getAttributes()
    {
        $setup = self::getSetupConfig();

        return [
            'service.name' => $setup['serviceName'],
            'service.version' => $setup['version'],
            'deployment.environment' => $setup['environment'],
        ];
    }

    public static function getTransport()
    {
        $setup = self::getSetupConfig();
        $client = new Client();
        $httpFactory = new HttpFactory();

        // Create the TransportInterface implementation (PsrTransport)
        $transport = new PsrTransport(
            $client,              // ClientInterface
            $httpFactory,         // RequestFactoryInterface
            $httpFactory,         // StreamFactoryInterface
            $setup['endpoint'],            // string endpoint
            'application/x-protobuf', // string contentType
            [],                   // array headers
            [],                   // array compression
            100,                  // int retryDelay (ms)
            3                     // int maxRetries
        );
        return $transport;
    }
    public static function getExporter(){

        $transport = self::getTransport();

        // Construct the exporter with the transport
        return new SpanExporter($transport);
    }

    public static function getTracer()
    {
        if (self::$tracer !== null) {
            return self::$tracer;
        }

        $setup = self::getSetupConfig();
        $resource_attributes = self::getAttributes();

        // Construct the exporter with the transport
        $exporter = self::getExporter();

        $resource_app_info = ResourceInfo::create(Attributes::create($resource_attributes));


        // Optionally enrich resource attributes (service.name, etc)
        $resource = ResourceInfoFactory::defaultResource()
            ->merge($resource_app_info);


        // Set up the tracer provider
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($exporter), // span processor(s)
            null,                               // sampler (optional, null for default)
            $resource                           // resource info
        );

        self::$tracer = $tracerProvider->getTracer(
            $setup['serviceName'],
            $setup['version']
        );

        return self::$tracer;
    }
    
    /**
     * Store the current span for correlation
     *
     * @param SpanInterface $span
     * @return void
     */
    public static function setCurrentSpan(SpanInterface $span)
    {
        self::$currentSpan = $span;
        
        // Store trace and span IDs for correlation with logs and exceptions
        $spanContext = $span->getContext();
        if ($spanContext instanceof SpanContextInterface) {
            // Get trace and span IDs in the correct format for OpenTelemetry
            $traceId = $spanContext->getTraceId();
            $spanId = $spanContext->getSpanId();
            
            // Format IDs as hex strings if they're not already
            if (!ctype_xdigit($traceId)) {
                $traceId = bin2hex($traceId);
            }
            
            if (!ctype_xdigit($spanId)) {
                $spanId = bin2hex($spanId);
            }
            
            // Store formatted IDs
            InstrumentationHelper::setCurrentTraceId($traceId);
            InstrumentationHelper::setCurrentSpanId($spanId);
            
            // If there's an exception ID, add it to the span for correlation
            $exceptionId = InstrumentationHelper::getCurrentExceptionId();
            if ($exceptionId) {
                $span->setAttribute('exception.id', $exceptionId);
                $span->setAttribute('log.correlation_id', $exceptionId);
            }
        }
    }
    
    /**
     * Get the current span
     *
     * @return SpanInterface|null
     */
    public static function getCurrentSpan()
    {
        return self::$currentSpan;
    }
    
    /**
     * Create a span with standardized naming and attributes
     * 
     * @param string $operationName Base operation name
     * @param array $attributes Additional attributes to add to the span
     * @param string $spanKind The kind of span (server, client, producer, consumer, internal)
     * @param SpanInterface|null $parentSpan Parent span if this is a child span
     * @return SpanInterface
     */
    public static function createSpan($operationName, $attributes = [], $spanKind = null, $parentSpan = null)
    {
        $tracer = self::getTracer();
        
        // Normalize operation name according to OpenTelemetry conventions
        // Format: <component>.<operation>.<detail>
        $normalizedName = self::normalizeSpanName($operationName);
        
        // Start building the span
        $spanBuilder = $tracer->spanBuilder($normalizedName);
        
        // Set the span kind if provided
        if ($spanKind) {
            $spanBuilder->setSpanKind($spanKind);
        }
        
        // Set the parent span if provided
        if ($parentSpan) {
            $spanBuilder->setParent($parentSpan->getContext());
        }
        
        // Add standard attributes that should be on all spans
        $standardAttributes = [
            'service.name' => config('vaahsignoz.otel.service_name', 'laravel-app'),
            'service.version' => config('vaahsignoz.otel.version', '1.0.0'),
            'deployment.environment' => config('vaahsignoz.otel.environment', 'production'),
            'host.name' => \WebReinvent\VaahSignoz\Helpers\InstrumentationHelper::getHostIdentifier()
        ];
        
        // Add request context if available
        if (request()) {
            $standardAttributes['http.method'] = request()->method();
            $standardAttributes['http.url'] = request()->fullUrl();
            $standardAttributes['http.target'] = request()->path();
            $standardAttributes['http.user_agent'] = request()->userAgent();
            
            // Add IP address information
            $standardAttributes['client.ip'] = request()->ip();
            $standardAttributes['client.real_ip'] = request()->header('X-Forwarded-For') ?? request()->ip();
            
            // Add browser and agent details
            if (request()->userAgent()) {
                $userAgent = request()->userAgent();
                $standardAttributes['client.user_agent'] = $userAgent;
                
                // Try to parse browser information
                if (function_exists('get_browser')) {
                    $browserInfo = @get_browser($userAgent, true);
                    if ($browserInfo) {
                        $standardAttributes['client.browser'] = $browserInfo['browser'] ?? 'unknown';
                        $standardAttributes['client.browser.version'] = $browserInfo['version'] ?? 'unknown';
                        $standardAttributes['client.platform'] = $browserInfo['platform'] ?? 'unknown';
                        $standardAttributes['client.device_type'] = $browserInfo['device_type'] ?? 'unknown';
                    }
                } else {
                    // Basic browser detection if get_browser() is not available
                    $browserInfo = [];
                    if (strpos($userAgent, 'Chrome') !== false) {
                        $browserInfo['browser'] = 'Chrome';
                    } elseif (strpos($userAgent, 'Firefox') !== false) {
                        $browserInfo['browser'] = 'Firefox';
                    } elseif (strpos($userAgent, 'Safari') !== false) {
                        $browserInfo['browser'] = 'Safari';
                    } elseif (strpos($userAgent, 'Edge') !== false) {
                        $browserInfo['browser'] = 'Edge';
                    } elseif (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident/') !== false) {
                        $browserInfo['browser'] = 'Internet Explorer';
                    } else {
                        $browserInfo['browser'] = 'Other';
                    }
                    
                    $standardAttributes['client.browser'] = $browserInfo['browser'];
                }
                
                // Mobile detection
                $standardAttributes['client.is_mobile'] = (
                    strpos($userAgent, 'Mobile') !== false || 
                    strpos($userAgent, 'Android') !== false || 
                    strpos($userAgent, 'iPhone') !== false || 
                    strpos($userAgent, 'iPad') !== false
                ) ? 'true' : 'false';
            }
            
            // Add location information from headers if available
            $standardAttributes['client.geo.country'] = request()->header('CF-IPCountry') ?? 'unknown';
            $standardAttributes['client.geo.city'] = request()->header('CF-IPCity') ?? 'unknown';
            $standardAttributes['client.geo.continent'] = request()->header('CF-IPContinent') ?? 'unknown';
            
            // Add user information if authenticated
            if (auth()->check()) {
                $user = auth()->user();
                $standardAttributes['user.id'] = $user->id ?? 'unknown';
                $standardAttributes['user.email'] = $user->email ?? 'unknown';
                $standardAttributes['user.name'] = $user->name ?? 'unknown';
                
                // Add roles if available
                if (method_exists($user, 'getRoleNames')) {
                    $standardAttributes['user.roles'] = implode(',', $user->getRoleNames()->toArray());
                }
                
                // Add tenant information if available
                if (request()->attributes->has('tenant')) {
                    $tenant = request()->attributes->get('tenant');
                    $standardAttributes['tenant.id'] = $tenant->id ?? 'unknown';
                    $standardAttributes['tenant.name'] = $tenant->name ?? 'unknown';
                    $standardAttributes['tenant.slug'] = $tenant->slug ?? 'unknown';
                }
            }
            
            // Add request headers (excluding sensitive ones)
            $headers = collect(request()->headers->all())->except(['authorization', 'cookie', 'x-csrf-token'])->toArray();
            if (!empty($headers)) {
                $formattedHeaders = [];
                foreach ($headers as $key => $values) {
                    $formattedHeaders[] = $key . ': ' . (is_array($values) ? implode(', ', $values) : $values);
                }
                $standardAttributes['http.request_headers'] = implode("\n", $formattedHeaders);
            }
            
            // Add session ID for correlation (but not the session content)
            if (request()->hasSession()) {
                $standardAttributes['session.id'] = request()->session()->getId();
            }
        }
        
        // Merge standard attributes with custom attributes
        $allAttributes = array_merge($standardAttributes, $attributes);
        
        // Add all attributes to the span
        foreach ($allAttributes as $key => $value) {
            $spanBuilder->setAttribute($key, $value);
        }
        
        // Start the span
        $span = $spanBuilder->startSpan();
        
        // Store the current span for correlation
        self::setCurrentSpan($span);
        
        return $span;
    }
    
    /**
     * Normalize span name according to OpenTelemetry conventions
     * 
     * @param string $name
     * @return string
     */
    protected static function normalizeSpanName($name)
    {
        // Remove any characters that aren't alphanumeric, dots, underscores, or hyphens
        $name = preg_replace('/[^a-zA-Z0-9\._\-]/', '_', $name);
        
        // Convert camelCase to snake_case for consistency
        $name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);
        
        // Convert to lowercase for consistency
        $name = strtolower($name);
        
        // Replace multiple dots with a single dot
        $name = preg_replace('/\.+/', '.', $name);
        
        // Replace multiple underscores with a single underscore
        $name = preg_replace('/_+/', '_', $name);
        
        // Limit the length to keep span names manageable
        if (strlen($name) > 100) {
            $name = substr($name, 0, 100);
        }
        
        return $name;
    }
}
