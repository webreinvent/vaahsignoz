<?php

namespace WebReinvent\VaahSignoz;

use WebReinvent\VaahSignoz\Instrumentation\CacheInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ClientInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ExceptionInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\LogInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\QueryInstrumentation;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;

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
            (new CacheInstrumentation())->boot();
        }
        if ($types['client'] ?? false) {
            (new ClientInstrumentation())->boot();
        }
        if ($types['exception'] ?? false) {
            (new ExceptionInstrumentation())->boot();
        }
        if ($types['log'] ?? false) {
            (new LogInstrumentation())->boot();
        }
        if ($types['query'] ?? false) {
            (new QueryInstrumentation())->boot();
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
