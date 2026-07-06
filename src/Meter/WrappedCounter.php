<?php

namespace WebReinvent\VaahSignoz\Meter;

/**
 * Thin wrapper around an OpenTelemetry counter that abstracts
 * SDK version differences.
 *
 * - SDK 1.2–1.5: Counter::add($value)  — no attributes param
 * - SDK 1.6+:    Counter::add($value, $attributes)
 */
class WrappedCounter
{
    private $counter;
    private static $supportsAttributes = null;

    public function __construct($counter)
    {
        $this->counter = $counter;
    }

    public function add($value, $attributes = [])
    {
        // Cache reflection result so we only pay the cost once
        if (self::$supportsAttributes === null) {
            try {
                $ref = new \ReflectionMethod($this->counter, 'add');
                self::$supportsAttributes = count($ref->getParameters()) > 1;
            } catch (\Throwable $e) {
                self::$supportsAttributes = false;
            }
        }

        if (self::$supportsAttributes && !empty($attributes)) {
            $this->counter->add($value, $attributes);
        } else {
            $this->counter->add($value);
        }

        return $this;
    }

    /**
     * Forward any other method calls to the underlying counter.
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->counter, $method], $args);
    }
}
