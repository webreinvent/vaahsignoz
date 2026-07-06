<?php

namespace WebReinvent\VaahSignoz\Meter;

/**
 * Thin wrapper around an OpenTelemetry histogram that abstracts
 * SDK version differences.
 *
 * - SDK 1.2–1.5: Histogram::record($value)  — no attributes param
 * - SDK 1.6+:    Histogram::record($value, $attributes)
 */
class WrappedHistogram
{
    private $histogram;
    private static $supportsAttributes = null;

    public function __construct($histogram)
    {
        $this->histogram = $histogram;
    }

    public function record($value, $attributes = [])
    {
        if (self::$supportsAttributes === null) {
            try {
                $ref = new \ReflectionMethod($this->histogram, 'record');
                self::$supportsAttributes = count($ref->getParameters()) > 1;
            } catch (\Throwable $e) {
                self::$supportsAttributes = false;
            }
        }

        if (self::$supportsAttributes && !empty($attributes)) {
            $this->histogram->record($value, $attributes);
        } else {
            $this->histogram->record($value);
        }

        return $this;
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->histogram, $method], $args);
    }
}
