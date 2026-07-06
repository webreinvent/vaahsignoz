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
 *   1. Track all queries per request (table + WHERE pattern).
 *   2. After each "parent" query (SELECT * FROM x, no single-key WHERE),
 *      watch for repeated single-key lookups on the same table.
 *   3. If count ≥ threshold, emit span + metric + log.
 */
class NPlusOneDetector
{
    protected $queries = [];
    protected $parentTables = [];
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

        // Track query
        $this->queries[] = [
            'table' => $table,
            'sql' => $sql,
            'time' => $event->time,
            'is_single_key_lookup' => $this->isSingleKeyLookup($sql),
        ];

        // Check for N+1 pattern
        $this->checkPattern($table);
    }

    /**
     * Check if a table has an N+1 pattern
     */
    protected function checkPattern(string $table)
    {
        // Skip if already detected this table
        if (isset($this->detected[$table])) {
            return;
        }

        // Find parent queries for this table
        $parentQuery = null;
        foreach ($this->queries as $i => $q) {
            if ($q['table'] === $table && !$q['is_single_key_lookup']) {
                $parentQuery = $i;
                break;
            }
        }

        if ($parentQuery === null) {
            return;
        }

        // Count single-key lookups after the parent query
        $count = 0;
        $totalTime = 0;

        for ($i = $parentQuery + 1; $i < count($this->queries); $i++) {
            if ($this->queries[$i]['table'] === $table && $this->queries[$i]['is_single_key_lookup']) {
                $count++;
                $totalTime += $this->queries[$i]['time'];
            }
        }

        if ($count >= $this->threshold) {
            $this->detected[$table] = [
                'parent_query_index' => $parentQuery,
                'count' => $count,
                'total_time_ms' => $totalTime,
                'table' => $table,
            ];

            $this->emit($table, $count, $totalTime);
        }
    }

    /**
     * Emit span + metric + log for detected N+1
     */
    protected function emit(string $table, int $count, float $totalTime)
    {
        $route = request() && request()->route() ? (request()->route()->getName() ?? request()->path()) : 'artisan';

        // Span
        if (config('vaahsignoz.n_plus_one.span', true)) {
            $span = TracerFactory::createSpan('db.n_plus_one', [
                'db.n_plus_one.parent_table' => $table,
                'db.n_plus_one.child_table' => $table,
                'db.n_plus_one.count' => $count,
                'db.n_plus_one.total_time_ms' => round($totalTime, 2),
                'http.route' => $route,
            ]);
            $span->end();
        }

        // Metric
        if (config('vaahsignoz.n_plus_one.metric', true)) {
            MeterFactory::counter('db.n_plus_one.total')
                ->add(1, [
                    'table' => $table,
                    'route' => $route,
                ]);
        }

        // Log
        if (config('vaahsignoz.n_plus_one.log', true)) {
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
        }
    }

    /**
     * Handle Laravel's Terminating event — reset state
     */
    public function handleTerminating()
    {
        $this->queries = [];
        $this->parentTables = [];
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
        // "FROM `table`" or "FROM table" or "into `table`"
        if (preg_match('/(?:FROM|INTO|UPDATE)\s+[`"\']?(\w+)[`"\']?/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function isSingleKeyLookup(string $sql): bool
    {
        // Look for WHERE id = ? or WHERE `id` = ? patterns
        return (bool) preg_match('/WHERE\s+`?id`?\s*=/', $sql, $matches);
    }
}
