<?php

namespace Webreinvent\VaahSignoz\Facades;

use Illuminate\Support\Facades\Facade;

class VaahSignoz extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'vaahsignoz';
    }
}
