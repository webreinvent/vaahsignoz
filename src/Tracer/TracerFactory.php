<?php

namespace WebReinvent\VaahSignoz\Tracer;

use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;

class TracerFactory
{
    protected static $tracer = null;

    public static function getTracer()
    {
        if (self::$tracer !== null) {
            return self::$tracer;
        }

        if (!class_exists('\OpenTelemetry\Exporter\Otlp\SpanExporter')) {
            throw new VaahSignozException(
                "OpenTelemetry SDK is missing. Please run `composer require open-telemetry/sdk open-telemetry/exporter-otlp`."
            );
        }

        $config = config('vaahsignoz');
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';
        $serviceName = $config['service_name'] ?? 'laravel-app';

        $exporter = new \OpenTelemetry\Exporter\Otlp\SpanExporter(
            endpoint: $endpoint,
            contentType: 'application/json'
        );

        $provider = new \OpenTelemetry\SDK\Trace\TracerProvider(
            new \OpenTelemetry\SDK\Trace\SimpleSpanProcessor($exporter),
            null,
            null,
            null,
            null,
            ['service.name' => $serviceName]
        );

        self::$tracer = $provider->getTracer('webreinvent.vaahsignoz');
        return self::$tracer;
    }
}
