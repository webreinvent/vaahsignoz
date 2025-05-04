<?php

namespace WebReinvent\VaahSignoz\Tracer;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransport;
use GuzzleHttp\Client;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;

class TracerFactory
{
    protected static $tracer = null;

    public static function getSetupConfig()
    {
        $config = config('vaahsignoz.otel');
        $setup['endpoint'] = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';
        $setup['serviceName'] = $config['service_name'] ?? 'laravel-app';
        $setup['version'] = $config['version'] ?? null;
        $setup['environment'] = $config['environment'] ?? null;

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
}
