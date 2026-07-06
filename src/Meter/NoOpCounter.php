<?php

namespace WebReinvent\VaahSignoz\Meter;

/**
 * No-op counter for when metrics are disabled.
 */
class NoOpCounter
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
