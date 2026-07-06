<?php

namespace WebReinvent\VaahSignoz\Tracer;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use GuzzleHttp\Client;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

class TracerFactory
{
    protected static $tracer = null;
    protected static $tracerProvider = null;
    protected static $currentSpan = null;
    protected static $sharedClient = null;

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

    public static function getSharedClient(): Client
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

    /**
     * Create a transport for a given endpoint using PsrTransportFactory.
     * Uses the factory pattern recommended by OTel PHP docs to avoid
     * constructor signature changes across SDK versions.
     *
     * PsrTransportFactory::discover() auto-discovers the PSR-18 HTTP client
     * (Guzzle, Symfony, etc.), eliminating constructor signature issues.
     */
    public static function createTransport(string $endpoint)
    {
        return PsrTransportFactory::discover()->create(
            $endpoint,
            ContentTypes::PROTOBUF
        );
    }

    public static function getTransport()
    {
        $setup = self::getSetupConfig();
        return self::createTransport($setup['endpoint']);
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

        // Version-agnostic resource creation: ResourceInfo::create()
        // - Older SDK: expects AttributesInterface
        // - Newer SDK: accepts plain array
        // Detect at runtime via reflection.
        $resource_app_info = self::createResourceInfo($resource_attributes);

        $resource = ResourceInfoFactory::defaultResource()
            ->merge($resource_app_info);

        // Sampler
        $sampler = self::createSampler();

        // BatchSpanProcessor for performance (buffers spans, exports in batches)
        // Support both old BatchSpanProcessorBuilder (1.x) and new BatchSpanProcessor::builder() patterns
        $processor = self::createBatchSpanProcessor($exporter);

        // Use builder pattern (TracerProvider::builder) if available (SDK 1.7+),
        // otherwise fall back to direct constructor for older versions.
        if (method_exists(TracerProvider::class, 'builder')) {
            $tracerProvider = TracerProvider::builder()
                ->addSpanProcessor($processor)
                ->setResource($resource)
                ->setSampler($sampler)
                ->build();
        } else {
            $tracerProvider = new TracerProvider(
                $processor,
                $sampler,
                $resource
            );
        }

        self::$tracerProvider = $tracerProvider;

        return $tracerProvider;
    }

    /**
     * Create BatchSpanProcessor with version-agnostic approach.
     * Supports both BatchSpanProcessorBuilder (1.x old) and BatchSpanProcessor::builder() (1.x new).
     */
    protected static function createBatchSpanProcessor($exporter)
    {
        $batchConfig = config('vaahsignoz.otel');

        // Try BatchSpanProcessor::builder() (newer 1.x API)
        if (method_exists(\OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor::class, 'builder')) {
            $builder = \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor::builder($exporter);

            if (method_exists($builder, 'setMaxExportBatchSize')) {
                $builder->setMaxExportBatchSize((int) ($batchConfig['batch_max_size'] ?? 512));
            }
            if (method_exists($builder, 'setScheduleDelay')) {
                $builder->setScheduleDelay((int) ($batchConfig['batch_timeout'] ?? 5000));
            }
            if (method_exists($builder, 'setExportTimeout')) {
                $builder->setExportTimeout((int) ($batchConfig['export_timeout'] ?? 3000));
            }

            return $builder->build();
        }

        // Fallback: BatchSpanProcessorBuilder (older 1.x API)
        $builder = new BatchSpanProcessorBuilder($exporter);

        if (method_exists($builder, 'setMaxExportBatchSize')) {
            $builder->setMaxExportBatchSize((int) ($batchConfig['batch_max_size'] ?? 512));
        }
        if (method_exists($builder, 'setScheduleDelay')) {
            $builder->setScheduleDelay((int) ($batchConfig['batch_timeout'] ?? 5000));
        }
        if (method_exists($builder, 'setExportTimeout')) {
            $builder->setExportTimeout((int) ($batchConfig['export_timeout'] ?? 3000));
        }

        return $builder->build();
    }

    /**
     * Create ResourceInfo with version-agnostic approach.
     *
     * - Newer SDK (1.6+): ResourceInfo::create() accepts plain array
     * - Older SDK (1.2-1.5): requires AttributesInterface
     *
     * Detects at runtime via reflection, caches the result.
     */
    protected static $resourceAcceptsArray = null;

    public static function createResourceInfo(array $attributes): ResourceInfo
    {
        if (self::$resourceAcceptsArray === null) {
            $ref = new \ReflectionMethod(ResourceInfo::class, 'create');
            $param = $ref->getParameters()[0] ?? null;
            self::$resourceAcceptsArray = $param && $param->getType() && $param->getType()->getName() === 'array';
        }

        if (self::$resourceAcceptsArray) {
            return ResourceInfo::create($attributes);
        }

        // Older SDK requires AttributesInterface
        return ResourceInfo::create(Attributes::create($attributes));
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
            // version-agnostic: shutdown() or forceFlush() depending on SDK version
            if (method_exists(self::$tracerProvider, 'shutdown')) {
                self::$tracerProvider->shutdown();
            } elseif (method_exists(self::$tracerProvider, 'forceFlush')) {
                self::$tracerProvider->forceFlush();
            }
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

        // Use explicit parent if provided, otherwise auto-link to current active span
        // This ensures all spans are part of the same trace tree
        $parent = $parentSpan ?? self::$currentSpan;
        if ($parent) {
            // activate() returns a ContextStorageNode with context(): ContextInterface
            $scope = $parent->activate();
            $spanBuilder->setParent($scope->context());
            $scope->detach();
        }

        // Add request-level attributes only when relevant (not on every span)
        $requestAttributes = self::getRequestAttributes();
        if (!empty($requestAttributes)) {
            $attributes = array_merge($requestAttributes, $attributes);
        }

        foreach ($attributes as $key => $value) {
            // OTel attributes must be scalar (string, bool, int, float) or arrays thereof
            if (is_scalar($value) || ($value === null)) {
                $spanBuilder->setAttribute($key, $value);
            } elseif (is_array($value)) {
                // Arrays of scalars are allowed
                $allScalar = true;
                foreach ($value as $item) {
                    if (!is_scalar($item) && $item !== null) {
                        $allScalar = false;
                        break;
                    }
                }
                if ($allScalar) {
                    $spanBuilder->setAttribute($key, $value);
                } else {
                    // Fallback: serialize non-scalar arrays
                    $spanBuilder->setAttribute($key, json_encode($value));
                }
            } else {
                // Non-scalar value — serialize to string
                $spanBuilder->setAttribute($key, is_object($value) ? get_class($value) : (string) $value);
            }
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
