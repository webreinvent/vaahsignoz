<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

/**
 * Tracks database transactions: begin, commit, rollback.
 * Uses a stack per connection to support nested transactions (save points).
 */
class TransactionInstrumentation
{
    /**
     * Stack of active transaction spans per connection.
     * [connection_name => [span1, span2, ...]]
     */
    protected static $activeTransactions = [];

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
        $connectionName = $event->connection->getName() ?? 'default';

        if (!isset(self::$activeTransactions[$connectionName])) {
            self::$activeTransactions[$connectionName] = [];
        }

        // Calculate nesting depth
        $depth = count(self::$activeTransactions[$connectionName]) + 1;

        $span = TracerFactory::createSpan('db.transaction', [
            'db.connection_name' => $connectionName,
            'db.transaction.event' => 'begin',
            'db.transaction.depth' => $depth,
        ]);

        // Push onto stack
        self::$activeTransactions[$connectionName][] = $span;
    }

    public function handleCommit(TransactionCommitted $event)
    {
        $connectionName = $event->connection->getName() ?? 'default';

        if (isset(self::$activeTransactions[$connectionName]) && count(self::$activeTransactions[$connectionName]) > 0) {
            // Pop from stack (LIFO — innermost transaction first)
            $span = array_pop(self::$activeTransactions[$connectionName]);
            $span->setAttribute('db.transaction.event', 'commit');
            $span->setAttribute('db.transaction.status', 'success');
            $span->end();

            // Clean up empty stacks
            if (count(self::$activeTransactions[$connectionName]) === 0) {
                unset(self::$activeTransactions[$connectionName]);
            }
        }
    }

    public function handleRollback(TransactionRolledBack $event)
    {
        $connectionName = $event->connection->getName() ?? 'default';

        if (isset(self::$activeTransactions[$connectionName]) && count(self::$activeTransactions[$connectionName]) > 0) {
            // Pop from stack
            $span = array_pop(self::$activeTransactions[$connectionName]);
            $span->setAttribute('db.transaction.event', 'rollback');
            $span->setAttribute('db.transaction.status', 'rolled_back');
            InstrumentationHelper::setSpanStatus($span, 'error', 'Transaction rolled back');
            $span->end();

            // Clean up empty stacks
            if (count(self::$activeTransactions[$connectionName]) === 0) {
                unset(self::$activeTransactions[$connectionName]);
            }
        }
    }
}
