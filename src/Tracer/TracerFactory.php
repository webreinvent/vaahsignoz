<?php

namespace WebReinvent\VaahSignoz\Tracer;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransport;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use GuzzleHttp\Client;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use GuzzleHttp\Psr7\HttpFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

class TracerFactory
{
    protected static $tracer = null;
    protected static $tracerProvider = null;
    protected static $currentSpan = null;
    protected static $sharedClient = null;
    protected static $meterProvider = null;

    /* ----------------------------------------------------------------- */
    /*  Config                                                            */
    /* ----------------------------------------------------------------- */

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

    /* ----------------------------------------------------------------- */
    /*  Shared HTTP Client (connection pooling)                          */
    /* ----------------------------------------------------------------- */

    protected static function getSharedClient(): Client
    {
        if (self::$sharedClient === null) {
            $otel = config('vaahsignoz.otel');
            self::$sharedClient = new Client([
                'timeout' => $otel['http_timeout'] ?? 3.0,
                'connect_timeout' => $otel['http_connect_timeout'] ?? 3.0,
            ]);
        }

        return self::$sharedClient;
    }

    /* ----------------------------------------------------------------- */
    /*  Transport / Exporter                                             */
    /* ----------------------------------------------------------------- */

    public static function getTransport()
    {
        $setup = self::getSetupConfig();
        $client = self::getSharedClient();
        $httpFactory = new HttpFactory();

        return new PsrTransport(
            $client,
            $httpFactory,
            $httpFactory,
            $setup['endpoint'],
            'application/x-protobuf',
            [],
            [],
            100,  // retryDelay ms
            3     // maxRetries
        );
    }

    public static function getExporter()
    {
        $transport = self::getTransport();

        return new SpanExporter($transport);
    }

    /* ----------------------------------------------------------------- */
    /*  TracerProvider                                                   */
    /* ----------------------------------------------------------------- */

    public static function getTracerProvider()
    {
        if (self::$tracerProvider !== null) {
            return self::$tracerProvider;
        }

        $setup = self::getSetupConfig();
        $resource_attributes = self::getAttributes();

        $exporter = self::getExporter();

        $resource_app_info = ResourceInfo::create(Attributes::create($resource_attributes));

        $resource = ResourceInfoFactory::defaultResource()
            ->merge($resource_app_info);

        // Sampler
        $sampler = self::createSampler();

        // BatchSpanProcessor for performance (buffers spans, exports in batches)
        $processor = (new BatchSpanProcessorBuilder($exporter))->build();

        $tracerProvider = new TracerProvider(
            $processor,
            $sampler,
            $resource
        );

        self::$tracerProvider = $tracerProvider;

        return $tracerProvider;
    }

    /**
     * Create sampler based on config
     */
    protected static function createSampler()
    {
        $samplerType = config('vaahsignoz.otel.sampler', 'always_on');
        $ratio = (float) config('vaahsignoz.otel.sampler_ratio', 1.0);

        switch ($samplerType) {
            case 'always_off':
                return new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler();
            case 'tracebased_per_million':
                return new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler($ratio);
            case 'parent_based':
                return new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
                    new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler()
                );
            case 'always_on':
            default:
                return new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler();
        }
    }

    public static function getTracer()
    {
        if (self::$tracer !== null) {
            return self::$tracer;
        }

        $setup = self::getSetupConfig();
        $tracerProvider = self::getTracerProvider();

        self::$tracer = $tracerProvider->getTracer(
            $setup['serviceName'],
            $setup['version']
        );

        return self::$tracer;
    }

    /* ----------------------------------------------------------------- */
    /*  Shutdown — flush + close (critical for not losing spans)         */
    /* ----------------------------------------------------------------- */

    public static function shutdown(): void
    {
        if (self::$tracerProvider !== null) {
            self::$tracerProvider->shutdown();
        }
    }

    /* ----------------------------------------------------------------- */
    /*  Span correlation                                                 */
    /* ----------------------------------------------------------------- */

    public static function setCurrentSpan(SpanInterface $span)
    {
        self::$currentSpan = $span;

        $spanContext = $span->getContext();
        if ($spanContext instanceof SpanContextInterface) {
            $traceId = $spanContext->getTraceId();
            $spanId = $spanContext->getSpanId();

            if (!ctype_xdigit($traceId)) {
                $traceId = bin2hex($traceId);
            }
            if (!ctype_xdigit($spanId)) {
                $spanId = bin2hex($spanId);
            }

            InstrumentationHelper::setCurrentTraceId($traceId);
            InstrumentationHelper::setCurrentSpanId($spanId);

            $exceptionId = InstrumentationHelper::getCurrentExceptionId();
            if ($exceptionId) {
                $span->setAttribute('exception.id', $exceptionId);
                $span->setAttribute('log.correlation_id', $exceptionId);
            }
        }
    }

    public static function getCurrentSpan()
    {
        return self::$currentSpan;
    }

    /* ----------------------------------------------------------------- */
    /*  createSpan — no duplicate attributes, lazy browser detection     */
    /* ----------------------------------------------------------------- */

    public static function createSpan($operationName, $attributes = [], $spanKind = null, $parentSpan = null)
    {
        $tracer = self::getTracer();

        $normalizedName = self::normalizeSpanName($operationName);

        $spanBuilder = $tracer->spanBuilder($normalizedName);

        if ($spanKind) {
            $spanBuilder->setSpanKind($spanKind);
        }

        if ($parentSpan) {
            $spanBuilder->setParent($parentSpan->getContext());
        }

        // Add request-level attributes only when relevant (not on every span)
        $requestAttributes = self::getRequestAttributes();
        if (!empty($requestAttributes)) {
            $attributes = array_merge($requestAttributes, $attributes);
        }

        foreach ($attributes as $key => $value) {
            $spanBuilder->setAttribute($key, $value);
        }

        $span = $spanBuilder->startSpan();

        self::setCurrentSpan($span);

        return $span;
    }

    /**
     * Get common request-level attributes (only set once per request)
     */
    protected static function getRequestAttributes(): array
    {
        $attrs = [];

        if (!request()) {
            return $attrs;
        }

        $attrs['http.method'] = request()->method();
        $attrs['http.target'] = request()->path();
        $attrs['http.client_ip'] = request()->ip();

        if (request()->route()) {
            $routeName = request()->route()->getName();
            if ($routeName) {
                $attrs['http.route'] = $routeName;
            }
        }

        if (auth()->check()) {
            $user = auth()->user();
            $attrs['user.id'] = (string) ($user->id ?? 'unknown');

            // PII masking
            if (config('vaahsignoz.security.pii_mask', false)) {
                $attrs['user.email'] = hash('sha256', $user->email ?? '');
                $attrs['user.name'] = hash('sha256', $user->name ?? '');
            } else {
                $attrs['user.email'] = $user->email ?? 'unknown';
                $attrs['user.name'] = $user->name ?? 'unknown';
            }
        }

        return $attrs;
    }

    /**
     * Normalize span name according to OpenTelemetry conventions
     */
    protected static function normalizeSpanName($name)
    {
        $name = preg_replace('/[^a-zA-Z0-9\._\-]/', '_', $name);
        $name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);
        $name = preg_replace('/\.+/', '.', $name);
        $name = preg_replace('/_+/', '_', $name);

        if (strlen($name) > 100) {
            $name = substr($name, 0, 100);
        }

        return $name;
    }
}
