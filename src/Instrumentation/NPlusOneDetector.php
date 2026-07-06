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
 * Algorithm:
 *   1. Track all queries per request with table name.
 *   2. Look for repeated queries on the same table with a single-row WHERE clause
 *      (e.g., WHERE id = ?, WHERE user_id = ?, WHERE tenant_id = ?).
 *   3. If the same table has ≥ threshold repeated single-row queries, it's N+1.
 *   4. Emit span + metric + log once per table per request.
 */
class NPlusOneDetector
{
    protected $queries = [];
    protected $detected = [];
    protected $threshold;

    public function __construct()
    {
        $this->threshold = config('vaahsignoz.n_plus_one.threshold', 10);
    }

    public function boot()
    {
        if (!config('vaahsignoz.n_plus_one.enabled', true)) {
            return;
        }

        Event::listen(QueryExecuted::class, [$this, 'handleQuery']);
        Event::listen(Terminating::class, [$this, 'handleTerminating']);
    }

    public function handleQuery(QueryExecuted $event)
    {
        $sql = $this->normalizeSql($event->sql);
        $table = $this->extractTable($sql);

        if (!$table) {
            return;
        }

        // Skip if already detected for this table
        if (isset($this->detected[$table])) {
            return;
        }

        // Track query
        $this->queries[$table][] = [
            'sql' => $sql,
            'time' => $event->time,
            'is_single_row_lookup' => $this->isSingleRowLookup($sql),
        ];

        // Count single-row lookups for this table
        $queries = $this->queries[$table];
        $singleRowCount = 0;

        foreach ($queries as $q) {
            if ($q['is_single_row_lookup']) {
                $singleRowCount++;
            }
        }

        if ($singleRowCount >= $this->threshold) {
            $totalTime = 0;
            foreach ($queries as $q) {
                if ($q['is_single_row_lookup']) {
                    $totalTime += $q['time'];
                }
            }

            $this->detected[$table] = true;
            $this->emit($table, $singleRowCount, $totalTime);
        }
    }

    /**
     * Emit span + metric + log for detected N+1
     */
    protected function emit(string $table, int $count, float $totalTime)
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
                        'threshold' => $this->threshold,
                    ]
                );
            } catch (\Throwable $_) {
                Log::warning(
                    "N+1 query detected on `{$table}` table — {$count} queries in request to '{$route}'",
                    [
                        'table' => $table,
                        'count' => $count,
                        'total_time_ms' => round($totalTime, 2),
                        'threshold' => $this->threshold,
                    ]
                );
            }
        }
    }

    /**
     * Handle Laravel's Terminating event — reset state for next request
     */
    public function handleTerminating()
    {
        $this->queries = [];
        $this->detected = [];
    }

    /* ----------------------------------------------------------------- */
    /*  SQL Parsing Helpers                                              */
    /* ----------------------------------------------------------------- */

    protected function normalizeSql(string $sql): string
    {
        return trim(str_replace(["\n", "\r", "\t"], ' ', $sql));
    }

    protected function extractTable(string $sql): ?string
    {
        // "FROM `table`" or "FROM table" or "into `table`" or "UPDATE `table`"
        if (preg_match('/(?:FROM|INTO|UPDATE|JOIN)\s+[`"\[]?(\w+)[`"\]]?/i', $sql, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Check if SQL is a single-row lookup — i.e., WHERE clause with
     * a single equality condition on a column that looks like a key
     * (id, *_id, primary key patterns).
     *
     * Matches:
     *   WHERE `id` = ?
     *   WHERE id = ?
     *   WHERE `user_id` = ?
     *   WHERE tenant_id = ?
     *   WHERE email = ?
     *   WHERE slug = ?
     */
    protected function isSingleRowLookup(string $sql): bool
    {
        // Match WHERE column = ? (single equality with a binding)
        // This catches: WHERE `id` = ?, WHERE user_id = ?, WHERE `email` = ?
        if (preg_match('/WHERE\s+[`"\[]?(\w+)[`"\]]?\s*=\s*[\?\$]/i', $sql, $m)) {
            $column = $m[1];

            // Check it's a key-like column: id, *_id, email, slug, uuid, token, code
            $keyColumns = ['id', 'email', 'slug', 'uuid', 'token', 'code', 'username', 'phone'];
            $isKeyColumn = false;

            foreach ($keyColumns as $key) {
                if ($column === $key) {
                    $isKeyColumn = true;
                    break;
                }
            }

            // Also match *_id pattern (user_id, tenant_id, post_id, etc.)
            if (preg_match('/_id$/', $column)) {
                $isKeyColumn = true;
            }

            return $isKeyColumn;
        }

        return false;
    }
}
