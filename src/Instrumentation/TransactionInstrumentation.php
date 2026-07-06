<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;

/**
 * Tracks database transactions: begin, commit, rollback.
 */
class TransactionInstrumentation
{
    protected static $activeTransactions = []; // [connection_name => span]

    public function boot()
    {
        if (!config('vaahsignoz.database.capture_transactions', true)) {
            return;
        }

        Event::listen(TransactionBeginning::class, [$this, 'handleBegin']);
        Event::listen(TransactionCommitted::class, [$this, 'handleCommit']);
        Event::listen(TransactionRolledBack::class, [$this, 'handleRollback']);
    }

    public function handleBegin(TransactionBeginning $event)
    {
        $connectionName = $event->connection->getName();

        $span = TracerFactory::createSpan('db.transaction', [
            'db.connection_name' => $connectionName,
            'db.transaction.event' => 'begin',
        ]);

        self::$activeTransactions[$connectionName] = $span;
    }

    public function handleCommit(TransactionCommitted $event)
    {
        $connectionName = $event->connection->getName();

        if (isset(self::$activeTransactions[$connectionName])) {
            $span = self::$activeTransactions[$connectionName];
            $span->setAttribute('db.transaction.event', 'commit');
            $span->setAttribute('db.transaction.status', 'success');
            $span->end();
            unset(self::$activeTransactions[$connectionName]);
        }
    }

    public function handleRollback(TransactionRolledBack $event)
    {
        $connectionName = $event->connection->getName();

        if (isset(self::$activeTransactions[$connectionName])) {
            $span = self::$activeTransactions[$connectionName];
            $span->setAttribute('db.transaction.event', 'rollback');
            $span->setAttribute('db.transaction.status', 'rolled_back');
            $span->setStatus(StatusCode::STATUS_ERROR, 'Transaction rolled back');
            $span->end();
            unset(self::$activeTransactions[$connectionName]);
        }
    }
}
