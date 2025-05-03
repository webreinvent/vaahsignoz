<?php

namespace Webreinvent\VaahSignoz;

use Webreinvent\VaahSignoz\Instrumentation\CacheInstrumentation;
use Webreinvent\VaahSignoz\Instrumentation\ClientInstrumentation;
use Webreinvent\VaahSignoz\Instrumentation\ExceptionInstrumentation;
use Webreinvent\VaahSignoz\Instrumentation\LogInstrumentation;
use Webreinvent\VaahSignoz\Instrumentation\QueryInstrumentation;
use Webreinvent\VaahSignoz\Exceptions\VaahSignozException;

class VaahSignoz
{

    protected array $customInstrumentations = [];

    /**
     * Automatically instrument enabled services.
     *
     * @throws VaahSignozException
     */

    public function registerInstrumentation(callable $bootCallback)
    {
        $this->customInstrumentations[] = $bootCallback;
    }
    public function autoInstrument()
    {
        $config = config('vaahsignoz');

        if (!$config['enabled']) {
            return;
        }

        $types = $config['instrumentations'] ?? [];

        if ($types['cache'] ?? false) {
            (new Instrumentation\CacheInstrumentation())->boot();
        }
        if ($types['client'] ?? false) {
            (new Instrumentation\ClientInstrumentation())->boot();
        }
        if ($types['exception'] ?? false) {
            (new Instrumentation\ExceptionInstrumentation())->boot();
        }
        if ($types['log'] ?? false) {
            (new Instrumentation\LogInstrumentation())->boot();
        }
        if ($types['query'] ?? false) {
            (new Instrumentation\QueryInstrumentation())->boot();
        }

        // Boot custom registered instrumentations
        foreach ($this->customInstrumentations as $custom) {
            $custom();
        }
    }

    /**
     * Manually force instrumentation for a specific service (for facades)
     * @param string $type
     * @throws VaahSignozException
     */
    public function instrument(string $type)
    {
        switch ($type) {
            case 'cache':
                (new CacheInstrumentation())->boot();
                break;
            case 'client':
                (new ClientInstrumentation())->boot();
                break;
            case 'exception':
                (new ExceptionInstrumentation())->boot();
                break;
            case 'log':
                (new LogInstrumentation())->boot();
                break;
            case 'query':
                (new QueryInstrumentation())->boot();
                break;
            default:
                throw new VaahSignozException("Unsupported instrumentation: $type");
        }
    }

    /**
     * Access underlying config (e.g., for endpoint, etc)
     */
    public function getConfig()
    {
        return config('vaahsignoz');
    }

}
