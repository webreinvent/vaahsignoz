<?php

namespace WebReinvent\VaahSignoz;

use WebReinvent\VaahSignoz\Instrumentation\CacheInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ClientInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ExceptionInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\LogInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\QueryInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\QueueInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\EventInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ViewInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\NPlusOneDetector;
use WebReinvent\VaahSignoz\Instrumentation\DatabaseErrorInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\TransactionInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\PhpErrorInstrumentation;
use WebReinvent\VaahSignoz\Instrumentation\ConnectionMonitorInstrumentation;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;

class VaahSignoz
{

    protected array $customInstrumentations = [];

    /**
     * Boot an instrumentation with try-catch so a single failure doesn't break all others.
     * Each instrumentation's own boot() method checks its config gate.
     */
    protected function safeBoot($instrumentation): void
    {
        try {
            $instrumentation->boot();
        } catch (\Throwable $_) {
            // This instrumentation failed — continue booting others
        }
    }

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

        // Core instrumentations
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

        // Extended instrumentations (Phase 4)
        if ($types['queue'] ?? false) {
            (new QueueInstrumentation())->boot();
        }
        if ($types['event'] ?? false) {
            (new EventInstrumentation())->boot();
        }
        if ($types['view'] ?? false) {
            (new ViewInstrumentation())->boot();
        }

        // N+1 Detection (Phase 4.5)
        $this->safeBoot(new NPlusOneDetector());

        // Database monitoring (Phase 4.6)
        $this->safeBoot(new DatabaseErrorInstrumentation());
        $this->safeBoot(new TransactionInstrumentation());
        $this->safeBoot(new ConnectionMonitorInstrumentation());

        // PHP error capture (Phase 4.6)
        $this->safeBoot(new PhpErrorInstrumentation());

        // Boot custom registered instrumentations
        foreach ($this->customInstrumentations as $custom) {
            try {
                $custom();
            } catch (\Throwable $_) {
                // Custom instrumentation failed — don't break the rest
            }
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
            case 'queue':
                (new QueueInstrumentation())->boot();
                break;
            case 'event':
                (new EventInstrumentation())->boot();
                break;
            case 'view':
                (new ViewInstrumentation())->boot();
                break;
            case 'n_plus_one':
                (new NPlusOneDetector())->boot();
                break;
            case 'db_errors':
                (new DatabaseErrorInstrumentation())->boot();
                break;
            case 'transactions':
                (new TransactionInstrumentation())->boot();
                break;
            case 'php_errors':
                (new PhpErrorInstrumentation())->boot();
                break;
            case 'connection_monitor':
                (new ConnectionMonitorInstrumentation())->boot();
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
