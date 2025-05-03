<?php

namespace WebReinvent\VaahSignoz\src\Services;

use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpHttpExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryService
{
    public static function init()
    {
        $exporter = new OtlpHttpExporter(
            endpoint: 'http://185.132.179.109:4318/v1/traces' // or your SigNoz OTLP endpoint
        );

        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($exporter)
        );

        return $tracerProvider->getTracer('oho.dev');
    }
}
