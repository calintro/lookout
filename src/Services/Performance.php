<?php

namespace Lookout\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Lookout\Lookout;
use NightOwl\Support\DDSketchHistogram;
use NightOwl\Support\QueryHistogram;
use NightOwl\Support\RollupTiers;

/**
 * Windowed performance from the NightOwl agent's rollup tables (minute, hourly, daily tiers). Durations are stored in
 * microseconds; p95 comes from the merged DDSketch (merged in PHP, see SKETCHES) and, for rows written before sketches, from
 * the v1 hist_NN bins scattered into sketch space, both with the package's own NightOwl\Support helpers.
 */
class Performance
{
    /** Candidate groups fetched (by total time) before sorting by p95 in PHP. */
    private const CANDIDATES = 500;

    public function connection(): ConnectionInterface
    {
        return DB::connection(Lookout::connection());
    }

    /**
     * The tier that covers the window at the finest granularity the retention keeps.
     */
    public static function table(string $baseTable, int $hours): string
    {
        return match (true) {
            $hours <= 48 => $baseTable,
            $hours <= 24 * 90 => RollupTiers::table($baseTable, 'hourly'),
            default => RollupTiers::table($baseTable, 'daily'),
        };
    }

    /**
     * The window's sketches as a comma list of hex payloads, merged in PHP by mergeSketches(). The agent's
     * nightowl_ddsketch_agg (plpgsql, a 1265-slot array copied per row) took 855 ms for the 36k query rows of 24 h;
     * string_agg takes 81 ms and the PHP merge a few ms (18.11.2026).
     */
    public const SKETCHES = "string_agg(encode(sketch, 'hex'), ',') filter (where length(sketch) > 0) as sketch";

    /** Packed sketch merged from a SKETCHES list, '' when there is none. */
    public static function mergeSketches(?string $hexList): string
    {
        // Most rows carry one of a few tiny sketches (8k distinct among 36k rows of 24 h), so each distinct one is
        // decoded once and weighted by how often it occurs.
        $counts = [];
        foreach (array_count_values(explode(',', (string) $hexList)) as $hex => $times) {
            if ($hex === '') {
                continue;
            }
            foreach (DDSketchHistogram::unpack(hex2bin((string) $hex)) as $index => $count) {
                $counts[$index] = ($counts[$index] ?? 0) + $count * $times;
            }
        }

        return DDSketchHistogram::pack($counts);
    }

    /**
     * p-th percentile in microseconds from a merged sketch plus v1 histogram bins, clamped to the observed min/max.
     *
     * @param  array<int|string, int|string|null>  $v1Bins  hist_00..hist_38 sums of rows without a sketch
     */
    public static function percentile(mixed $sketch, array $v1Bins, ?int $min, ?int $max, float $p = 0.95): ?float
    {
        $counts = DDSketchHistogram::countsFromDb($sketch) ?? [];
        $bins = array_map('intval', array_values($v1Bins));

        if (array_sum($bins) > 0) {
            $counts = DDSketchHistogram::mergeCounts($counts, DDSketchHistogram::upconvert($bins));
        }

        if (array_sum($counts) <= 0) {
            return null;
        }

        return DDSketchHistogram::percentile($counts, $p, $min, $max);
    }

    /**
     * Requests by route over the last $hours.
     *
     * @return list<array{route: string, calls: int, client_errors: int, server_errors: int, error_rate: float, avg_ms: ?float, p95_ms: ?float}>
     */
    public function routes(int $hours, int $limit = 20, string $sort = 'p95'): array
    {
        $rows = $this->aggregate('nightowl_request_rollups', $hours, [
            'max(route_methods) as route_methods',
            'max(route_path) as route_path',
            'sum(client_error_count) as client_errors',
            'sum(server_error_count) as server_errors',
        ]);

        $result = array_map(function (object $row): array {
            $calls = (int) $row->calls;
            $methods = json_decode((string) $row->route_methods, true);
            $method = is_array($methods) ? implode('|', array_diff($methods, ['HEAD'])) : (string) $row->route_methods;

            return [
                'route' => trim($method.' '.($row->route_path ?? $row->group_hash)),
                'calls' => $calls,
                'client_errors' => (int) $row->client_errors,
                'server_errors' => (int) $row->server_errors,
                'error_rate' => $calls > 0 ? round(100 * ((int) $row->client_errors + (int) $row->server_errors) / $calls, 2) : 0.0,
                'avg_ms' => self::ms($calls > 0 ? (int) $row->total_duration / $calls : null),
                'p95_ms' => self::ms($row->p95),
            ];
        }, $rows);

        return self::top($result, $sort, $limit);
    }

    /**
     * Database queries by normalised SQL over the last $hours.
     *
     * @return list<array{sql: string, connection: string, calls: int, total_ms: ?float, avg_ms: ?float, p95_ms: ?float}>
     */
    public function queries(int $hours, int $limit = 20, string $sort = 'p95'): array
    {
        $rows = $this->aggregate('nightowl_query_rollups', $hours, [
            'max(sql_query) as sql_query',
            'max(connection) as connection',
        ]);

        $result = array_map(fn (object $row): array => [
            'sql' => (string) ($row->sql_query ?? $row->group_hash),
            'connection' => (string) $row->connection,
            'calls' => (int) $row->calls,
            'total_ms' => self::ms((int) $row->total_duration),
            'avg_ms' => self::ms((int) $row->calls > 0 ? (int) $row->total_duration / (int) $row->calls : null),
            'p95_ms' => self::ms($row->p95),
        ], $rows);

        return self::top($result, $sort, $limit);
    }

    /**
     * Queued jobs by class over the last $hours. $failedOnly keeps the classes with at least one failure.
     *
     * @return list<array{job: string, queue: ?string, attempts: int, processed: int, failed: int, released: int, avg_ms: ?float, p95_ms: ?float}>
     */
    public function jobs(int $hours, int $limit = 20, bool $failedOnly = false, string $sort = 'p95'): array
    {
        $rows = $this->aggregate('nightowl_job_rollups', $hours, [
            'max(job_class) as job_class',
            'max(queue) as queue',
            'sum(processed_count) as processed',
            'sum(failed_count) as failed',
            'sum(released_count) as released',
            'sum(attempts_count) as attempts',
        ], $failedOnly ? 'sum(failed_count) > 0' : null);

        $result = array_map(fn (object $row): array => [
            'job' => (string) ($row->job_class ?? $row->group_hash),
            'queue' => $row->queue,
            'attempts' => (int) $row->attempts,
            'processed' => (int) $row->processed,
            'failed' => (int) $row->failed,
            'released' => (int) $row->released,
            'avg_ms' => self::ms((int) $row->attempts > 0 ? (int) $row->total_duration / (int) $row->attempts : null),
            'p95_ms' => self::ms($row->p95),
        ], $rows);

        return self::top($result, $failedOnly && $sort === 'p95' ? 'failed' : $sort, $limit);
    }

    /**
     * Whole-app request totals over the last $hours.
     *
     * @return array{calls: int, server_errors: int, client_errors: int, error_rate: float, p95_ms: ?float}
     */
    public function overview(int $hours): array
    {
        $row = $this->aggregate('nightowl_request_rollups', $hours, [
            'sum(client_error_count) as client_errors',
            'sum(server_error_count) as server_errors',
        ], null, false)[0] ?? null;

        $calls = (int) ($row->calls ?? 0);

        return [
            'calls' => $calls,
            'server_errors' => (int) ($row->server_errors ?? 0),
            'client_errors' => (int) ($row->client_errors ?? 0),
            'error_rate' => $calls > 0 ? round(100 * (int) $row->server_errors / $calls, 2) : 0.0,
            'p95_ms' => self::ms($row->p95 ?? null),
        ];
    }

    /**
     * Sums one rollup family over the window, per group_hash (or as one row), with p95 per row.
     *
     * @param  list<string>  $selects
     * @return list<object>
     */
    private function aggregate(string $baseTable, int $hours, array $selects, ?string $having = null, bool $grouped = true): array
    {
        $hours = max(1, $hours);
        $bins = QueryHistogram::columns();
        $binSums = array_map(fn (string $column): string => "sum({$column}) filter (where sketch is null) as {$column}", $bins);

        $query = $this->connection()->table(self::table($baseTable, $hours))
            ->where('bucket_start', '>=', now('UTC')->subHours($hours)->format('Y-m-d H:i:s'))
            ->selectRaw(implode(', ', array_merge(
                $grouped ? ['group_hash'] : [],
                ['sum(call_count) as calls', 'sum(total_duration) as total_duration', 'min(min_duration) as min_duration',
                    'max(max_duration) as max_duration', self::SKETCHES],
                $selects,
                $binSums,
            )));

        if ($grouped) {
            $query->groupBy('group_hash')->orderByRaw('sum(total_duration) desc')->limit(self::CANDIDATES);
        }

        if ($having !== null) {
            $query->havingRaw($having);
        }

        $rows = $query->get()->all();

        foreach ($rows as $row) {
            $row->p95 = self::percentile(
                self::mergeSketches($row->sketch),
                array_map(fn (string $column) => $row->{$column}, $bins),
                $row->min_duration !== null ? (int) $row->min_duration : null,
                $row->max_duration !== null ? (int) $row->max_duration : null,
            );
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function top(array $rows, string $sort, int $limit): array
    {
        $key = match ($sort) {
            'calls', 'failed', 'error_rate', 'avg_ms', 'total_ms' => $sort,
            default => 'p95_ms',
        };

        usort($rows, fn (array $a, array $b): int => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));

        return array_slice($rows, 0, max(1, min($limit, 100)));
    }

    private static function ms(int|float|null $microseconds): ?float
    {
        return $microseconds !== null ? round($microseconds / 1000, 1) : null;
    }
}
