<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\QueryExecuted;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;

class QueryInstrumentation
{
    public function boot()
    {
        try {
            Event::listen(QueryExecuted::class, [$this, 'handleQuery']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot query instrumentation.', 0, $e);
        }
    }

    public function handleQuery(QueryExecuted $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('db.query')->startSpan();
        $span->setAttribute('db.system', $event->connection->getDriverName());
        $span->setAttribute('db.statement', $event->sql);
        $span->setAttribute('db.bindings', json_encode($event->bindings));
        $span->setAttribute('db.time', $event->time);
        $span->setAttribute('db.connection_name', $event->connectionName);
        $span->end();
    }
}
