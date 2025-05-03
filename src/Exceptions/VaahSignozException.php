<?php

namespace WebReinvent\VaahSignoz\Exceptions;

use Exception;

class VaahSignozException extends Exception
{
    public static function missingSdk()
    {
        return new static(
            "OpenTelemetry SDK is required but not installed. Run: composer require open-telemetry/sdk open-telemetry/exporter-otlp"
        );
    }

    public static function unsupportedInstrumentation($type)
    {
        return new static(
            "Unsupported instrumentation type: $type. Please use one of: cache, client, exception, log, query."
        );
    }
}
