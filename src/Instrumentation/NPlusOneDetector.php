<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Database\Events\QueryExecuted;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Events\Terminating;

/**
 * Detects N+1 query patterns per request.
 *
 * Lightweight implementation: tracks only counts per table (no SQL strings).
 * Emits once per table per request when threshold is exceeded.
 * Uses static state to avoid closure capture of $this.
 */
class NPlusOneDetector
{
    protected static $singleRowCounts = []; // [table] => int
    protected static $totalTimes = [];     // [table] => float
    protected static $detected = [];       // [table] => bool
    protected static $threshold = 10;

    public function boot()
    {
        if (!config('vaahsignoz.n_plus_one.enabled', true)) {
            return;
        }

        self::$threshold = config('vaahsignoz.n_plus_one.threshold', 10);

        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) {
            $table = self::extractTable($event->sql);
            if (!$table) {
                return;
            }

            // Skip if already detected for this table
            if (isset(self::$detected[$table])) {
                return;
            }

            // Only track single-row lookups (no SQL string storage)
            if (self::isSingleRowLookup($event->sql)) {
                self::$singleRowCounts[$table] = (self::$singleRowCounts[$table] ?? 0) + 1;
                self::$totalTimes[$table] = (self::$totalTimes[$table] ?? 0) + $event->time;

                if (self::$singleRowCounts[$table] >= self::$threshold) {
                    self::$detected[$table] = true;
                    self::emit($table, self::$singleRowCounts[$table], self::$totalTimes[$table] ?? 0);
                }
            }
        });

        Event::listen(Terminating::class, static function () {
            self::$singleRowCounts = [];
            self::$totalTimes = [];
            self::$detected = [];
        });
    }

    /**
     * Emit span + metric + log for detected N+1
     */
    protected static function emit(string $table, int $count, float $totalTime)
    {
        $route = request() && request()->route()
            ? (request()->route()->getName() ?? request()->path())
            : 'artisan';

        // Span
        if (config('vaahsignoz.n_plus_one.span', true)) {
            $span = TracerFactory::createSpan('db.n_plus_one', [
                'db.n_plus_one.table' => $table,
                'db.n_plus_one.count' => $count,
                'db.n_plus_one.total_time_ms' => round($totalTime, 2),
                'http.route' => $route,
            ]);
            $span->end();
        }

        // Metric
        if (config('vaahsignoz.n_plus_one.metric', true)) {
            try {
                MeterFactory::counter('db.n_plus_one.total')
                    ->add(1, [
                        'table' => $table,
                        'route' => $route,
                    ]);
            } catch (\Throwable $_) {
                // Meter may not be ready
            }
        }

        // Log
        if (config('vaahsignoz.n_plus_one.log', true)) {
            try {
                Log::channel('signoz')->warning(
                    "N+1 query detected on `{$table}` table — {$count} queries in request to '{$route}'",
                    [
                        'table' => $table,
                        'count' => $count,
                        'total_time_ms' => round($totalTime, 2),
                        'route' => $route,
                        'threshold' => self::$threshold,
                    ]
                );
            } catch (\Throwable $_) {
                Log::warning(
                    "N+1 query detected on `{$table}` table — {$count} queries in request to '{$route}'",
                    [
                        'table' => $table,
                        'count' => $count,
                        'total_time_ms' => round($totalTime, 2),
                        'threshold' => self::$threshold,
                    ]
                );
            }
        }
    }

    /* ----------------------------------------------------------------- */
    /*  SQL Parsing Helpers (static — no $this capture)                  */
    /* ----------------------------------------------------------------- */

    protected static function extractTable(string $sql): ?string
    {
        if (preg_match('/(?:FROM|INTO|UPDATE|JOIN)\s+[`"\[]?(\w+)[`"\]]?/i', $sql, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    protected static function isSingleRowLookup(string $sql): bool
    {
        $column = null;

        if (preg_match('/WHERE\s+[`"\[]?(?:\w+\.)?(\w+)[`"\]]?\s*=\s*[\?\$]/i', $sql, $m)) {
            $column = $m[1];
        }
        elseif (preg_match('/\bAND\s+[`"\[]?(?:\w+\.)?(\w+)[`"\]]?\s*=\s*[\?\$]/i', $sql, $m)) {
            $column = $m[1];
        }

        if (!$column) {
            return false;
        }

        $keyColumns = ['id', 'email', 'slug', 'uuid', 'token', 'code', 'username', 'phone'];

        if (in_array($column, $keyColumns, true)) {
            return true;
        }

        if (str_ends_with($column, '_id')) {
            return true;
        }

        return false;
    }
}
