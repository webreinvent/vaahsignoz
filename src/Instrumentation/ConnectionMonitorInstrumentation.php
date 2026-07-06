<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

/**
 * Monitors database connection pool health using Laravel's
 * database monitoring events and query listener.
 */
class ConnectionMonitorInstrumentation
{
    protected static $activeConnections = [];

    /**
     * Reset active connections. Called on app->terminating()
     * to prevent memory leaks in PHP-FPM.
     */
    public static function reset(): void
    {
        self::$activeConnections = [];
    }

    public function boot()
    {
        if (!config('vaahsignoz.database.monitor_connections', true)) {
            return;
        }

        // Track connections via QueryExecuted events
        Event::listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event) {
            $connectionName = $event->connection->getName() ?? 'default';

            self::$activeConnections[$connectionName] = time();

            // Gauge: active connections
            try {
                $count = count(array_unique(self::$activeConnections));
                MeterFactory::gauge('db.connections.active')
                    ->add($count, ['connection_name' => $connectionName]);
            } catch (\Throwable $_) {
                // Meter may not be ready
            }
        });

        // Track connection reconnection events
        if (class_exists(\Illuminate\Database\Events\ConnectionEstablished::class)) {
            Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class, function ($event) {
                try {
                    MeterFactory::counter('db.connections.established')->add(1, [
                        'connection_name' => $event->connection->getName() ?? 'default',
                    ]);
                } catch (\Throwable $_) {
                }
            });
        }
    }
}
