<?php

namespace WebReinvent\VaahSignoz\Meter;

/**
 * No-op up-down counter for when metrics are disabled.
 */
class NoOpUpDownCounter
{
    public function add($value, $attributes = [])
    {
        return $this;
    }

    public function __call($method, $args)
    {
        return $this;
    }
}
