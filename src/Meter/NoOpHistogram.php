<?php

namespace WebReinvent\VaahSignoz\Meter;

/**
 * No-op histogram for when metrics are disabled.
 */
class NoOpHistogram
{
    public function record($value, $attributes = [])
    {
        return $this;
    }

    public function __call($method, $args)
    {
        return $this;
    }
}
