<?php

namespace WebReinvent\VaahSignoz\Facades;

use Illuminate\Support\Facades\Facade;

class VaahSignoz extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'vaahsignoz';
    }
}
