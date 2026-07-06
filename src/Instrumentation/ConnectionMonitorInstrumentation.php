<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use WebReinvent\VaahSignoz\Meter\MeterFactory;

/**
 * Monitors database connection pool health.
 * Lightweight: only tracks connection events (not per-query).
 */
class ConnectionMonitorInstrumentation
{
    public function boot()
    {
        if (!config('vaahsignoz.database.monitor_connections', true)) {
            return;
        }

        // Track connection establishment events only (not every query)
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

        // Record active connections on app terminating (lightweight snapshot)
        app()->terminating(function () {
            try {
                $db = app('db');
                if (is_null($db)) {
                    return;
                }

                $connections = $db->getConnections() ?? [];
                foreach ($connections as $connection) {
                    $name = $connection->getName() ?? 'default';
                    MeterFactory::gauge('db.connections.active')
                        ->add(1, ['connection_name' => $name]);
                }
            } catch (\Throwable $_) {
            }
        });
    }
}
