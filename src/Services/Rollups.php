<?php

namespace Lookout\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Lookout\Lookout;
use NightOwl\Support\QueryHistogram;

/**
 * Reads the agent's rollup families (minute / hourly / daily tiers picked by Window) as time series or grouped
 * lists. Durations are stored in microseconds and returned in milliseconds; p95 merges the DDSketch and v1 bins
 * exactly like Lookout\Services\Performance. Column and table names here are constants, never request input.
 */
class Rollups
{
    public function db(): ConnectionInterface
    {
        return DB::connection(Lookout::connection());
    }

    public function query(string $baseTable, Window $window, ?string $environment): Builder
    {
        $query = $this->db()->table($window->table($baseTable))
            ->where('bucket_start', '>=', $window->fromSql())
            ->where('bucket_start', '<', $window->toSql());

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        return $query;
    }

    /**
     * Aggregates that p95()/avg need: totals, min/max, merged sketch and the v1 bins of rows without a sketch.
     *
     * @return list<string>
     */
    public static function durationSelects(): array
    {
        return array_merge(
            ['sum(total_duration) as total_duration', 'min(min_duration) as min_duration', 'max(max_duration) as max_duration',
                Performance::SKETCHES],
            array_map(fn (string $c): string => "sum({$c}) filter (where sketch is null) as {$c}", QueryHistogram::columns()),
        );
    }

    /**
     * p95 in ms of a row selected with durationSelects().
     */
    public static function p95(object $row): ?float
    {
        $us = Performance::percentile(
            Performance::mergeSketches($row->sketch ?? null),
            array_map(fn (string $c) => $row->{$c} ?? 0, QueryHistogram::columns()),
            isset($row->min_duration) ? (int) $row->min_duration : null,
            isset($row->max_duration) ? (int) $row->max_duration : null,
        );

        return self::ms($us);
    }

    public static function ms(int|float|string|null $microseconds): ?float
    {
        return $microseconds !== null ? round((float) $microseconds / 1000, 1) : null;
    }

    public static function avg(object $row, string $countColumn = 'calls'): ?float
    {
        $count = (int) ($row->{$countColumn} ?? 0);

        return $count > 0 ? self::ms((int) $row->total_duration / $count) : null;
    }

    /**
     * One row per bucket of the window (gaps filled with $empty), keyed by ISO bucket start.
     *
     * @param  list<string>  $selects
     * @param  callable(object): array<string, mixed>  $map
     * @param  callable(Builder): void|null  $scope
     * @return list<array<string, mixed>>
     */
    public function series(string $baseTable, Window $window, ?string $environment, array $selects, callable $map, ?callable $scope = null): array
    {
        $query = $this->query($baseTable, $window, $environment)
            ->selectRaw(implode(', ', array_merge([$window->bucketExpression().' as bucket'], $selects)))
            ->groupByRaw('1');

        if ($scope !== null) {
            $scope($query);
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[gmdate('Y-m-d\TH:i:s\Z', strtotime($row->bucket.' UTC'))] = $row;
        }

        return array_map(
            fn (string $start): array => ['t' => $start, ...$map($rows[$start] ?? null)],
            $window->bucketStarts(),
        );
    }

    /**
     * Totals over the whole window as one row.
     *
     * @param  list<string>  $selects
     * @param  callable(Builder): void|null  $scope
     */
    public function totals(string $baseTable, Window $window, ?string $environment, array $selects, ?callable $scope = null): object
    {
        $query = $this->query($baseTable, $window, $environment)->selectRaw(implode(', ', $selects));

        if ($scope !== null) {
            $scope($query);
        }

        return $query->first() ?? (object) [];
    }

    /**
     * Groups by $groupColumns, ordered by $orderBy (a whitelisted aggregate), capped at $limit.
     *
     * @param  list<string>  $groupColumns
     * @param  list<string>  $selects
     * @param  callable(Builder): void|null  $scope
     * @return list<object>
     */
    public function grouped(string $baseTable, Window $window, ?string $environment, array $groupColumns, array $selects, string $orderBy, int $limit, ?callable $scope = null): array
    {
        $query = $this->query($baseTable, $window, $environment)
            ->select($groupColumns)
            ->selectRaw(implode(', ', $selects))
            ->groupBy($groupColumns)
            ->orderByRaw($orderBy)
            ->limit($limit);

        if ($scope !== null) {
            $scope($query);
        }

        return $query->get()->all();
    }
}
