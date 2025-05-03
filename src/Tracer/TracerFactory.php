<?php

namespace WebReinvent\VaahSignoz\Tracer;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransport;
use GuzzleHttp\Client;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

use GuzzleHttp\Psr7\HttpFactory;

class TracerFactory
{
    protected static $tracer = null;

    public static function getTracer()
    {
        if (self::$tracer !== null) {
            return self::$tracer;
        }

        $config = config('vaahsignoz.otel');
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';
        $serviceName = $config['service_name'] ?? 'laravel-app';

        $client = new Client();
        $httpFactory = new HttpFactory();

        // Create the TransportInterface implementation (PsrTransport)
        $transport = new PsrTransport(
            $client,              // ClientInterface
            $httpFactory,         // RequestFactoryInterface
            $httpFactory,         // StreamFactoryInterface
            $endpoint,            // string endpoint
            'application/x-protobuf', // string contentType
            [],                   // array headers
            [],                   // array compression
            100,                  // int retryDelay (ms)
            3                     // int maxRetries
        );

        // Construct the exporter with the transport
        $exporter = new SpanExporter($transport);

        // Optionally enrich resource attributes (service.name, etc)
        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfoFactory::defaultResource());

        // Set up the tracer provider
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($exporter), // span processor(s)
            null,                               // sampler (optional, null for default)
            $resource                           // resource info
        );

        self::$tracer = $tracerProvider->getTracer($serviceName);

        return self::$tracer;
    }
}
