<?php

namespace Lookout\Services;

use Cron\CronExpression;
use Illuminate\Database\Query\Builder;
use Lookout\Models\Issue;

/**
 * Overview and per-family lists of Lookout, from the rollup tables.
 */
class Telemetry
{
    /** Groups fetched from SQL before sorting by a computed column (p95, rates) in PHP. */
    public const CANDIDATES = 500;

    public function __construct(private Rollups $rollups, private Raw $raw, private Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Window $window, ?string $environment): array
    {
        $durations = Rollups::durationSelects();
        $requestSelects = ['sum(call_count) as calls', 'sum(success_count) as success', 'sum(client_error_count) as client_errors',
            'sum(server_error_count) as server_errors', ...$durations];
        $redirects = $this->raw->redirectsByBucket($window, $environment);

        $requests = $this->rollups->series('nightowl_request_rollups', $window, $environment, $requestSelects, function (?object $r) use (&$redirects): array {
            return ['calls' => (int) ($r->calls ?? 0), 'success' => (int) ($r->success ?? 0), 'status_4xx' => (int) ($r->client_errors ?? 0),
                'status_5xx' => (int) ($r->server_errors ?? 0), 'avg_ms' => $r ? Rollups::avg($r) : null, 'p95_ms' => $r ? Rollups::p95($r) : null,
                'min_ms' => Rollups::ms($r->min_duration ?? null), 'max_ms' => Rollups::ms($r->max_duration ?? null)];
        });
        foreach ($requests as &$bucket) {
            $three = $redirects === null ? null : (int) ($redirects[$bucket['t']] ?? 0);
            $bucket['status_3xx'] = $three;
            $bucket['status_2xx'] = $three === null ? null : max(0, $bucket['success'] - $three);
        }
        unset($bucket);
        $requestTotals = $this->rollups->totals('nightowl_request_rollups', $window, $environment, $requestSelects);

        $exceptionSelects = ['sum(call_count) as total', 'sum(handled_count) as handled', 'sum(unhandled_count) as unhandled'];
        $exceptions = $this->rollups->series('nightowl_exception_rollups', $window, $environment, $exceptionSelects, fn (?object $r): array => [
            'total' => (int) ($r->total ?? 0), 'handled' => (int) ($r->handled ?? 0), 'unhandled' => (int) ($r->unhandled ?? 0),
        ]);
        $exceptionTotals = $this->rollups->totals('nightowl_exception_rollups', $window, $environment, $exceptionSelects);
        $impactedSeries = $this->rollups->series('nightowl_user_exception_rollups', $window, $environment, ['count(distinct user_id) as users'],
            fn (?object $r): array => ['users' => (int) ($r->users ?? 0)], fn (Builder $q) => $q->where('user_id', '!=', ''));
        foreach ($exceptions as $i => &$bucket) {
            $bucket['users'] = $impactedSeries[$i]['users'] ?? 0;
        }
        unset($bucket);

        $jobSelects = ['sum(attempts_count) as attempts', 'sum(processed_count) as processed', 'sum(failed_count) as failed', 'sum(released_count) as released', ...$durations];
        $jobs = $this->rollups->series('nightowl_job_rollups', $window, $environment, $jobSelects, fn (?object $r): array => [
            'processed' => (int) ($r->processed ?? 0), 'failed' => (int) ($r->failed ?? 0), 'released' => (int) ($r->released ?? 0),
            'avg_ms' => $r ? Rollups::avg($r, 'attempts') : null, 'p95_ms' => $r ? Rollups::p95($r) : null,
        ]);
        $jobTotals = $this->rollups->totals('nightowl_job_rollups', $window, $environment, $jobSelects);

        $outgoingSelects = ['sum(call_count) as calls', 'sum(client_error_count) + sum(server_error_count) as errors', ...$durations];
        $outgoing = $this->rollups->series('nightowl_outgoing_request_rollups', $window, $environment, $outgoingSelects, fn (?object $r): array => [
            'calls' => (int) ($r->calls ?? 0), 'errors' => (int) ($r->errors ?? 0), 'p95_ms' => $r ? Rollups::p95($r) : null,
        ]);
        $outgoingTotals = $this->rollups->totals('nightowl_outgoing_request_rollups', $window, $environment, $outgoingSelects);

        $users = $this->rollups->series('nightowl_user_rollups', $window, $environment, ['count(distinct user_id) as users', 'sum(call_count) as calls'],
            fn (?object $r): array => ['users' => (int) ($r->users ?? 0), 'requests' => (int) ($r->calls ?? 0)], fn (Builder $q) => $q->where('user_id', '!=', ''));
        $userTotal = $this->rollups->totals('nightowl_user_rollups', $window, $environment, ['count(distinct user_id) as users'], fn (Builder $q) => $q->where('user_id', '!=', ''));
        $impacted = $this->rollups->totals('nightowl_user_exception_rollups', $window, $environment, ['count(distinct user_id) as users'], fn (Builder $q) => $q->where('user_id', '!=', ''));

        $calls = (int) ($requestTotals->calls ?? 0);
        $redirectTotal = $redirects === null ? null : array_sum($redirects);

        return [
            'window' => $window->toArray(),
            'environment' => $environment,
            'environments' => $this->raw->environments(),
            'totals' => [
                'requests' => [
                    'calls' => $calls,
                    'status_2xx' => $redirectTotal === null ? null : max(0, (int) ($requestTotals->success ?? 0) - $redirectTotal),
                    'status_3xx' => $redirectTotal,
                    'status_4xx' => (int) ($requestTotals->client_errors ?? 0),
                    'status_5xx' => (int) ($requestTotals->server_errors ?? 0),
                    'avg_ms' => isset($requestTotals->total_duration) ? Rollups::avg($requestTotals) : null,
                    'p95_ms' => isset($requestTotals->total_duration) ? Rollups::p95($requestTotals) : null,
                    'min_ms' => Rollups::ms($requestTotals->min_duration ?? null),
                    'max_ms' => Rollups::ms($requestTotals->max_duration ?? null),
                ],
                'exceptions' => ['total' => (int) ($exceptionTotals->total ?? 0), 'handled' => (int) ($exceptionTotals->handled ?? 0), 'unhandled' => (int) ($exceptionTotals->unhandled ?? 0),
                    'users_impacted' => (int) ($impacted->users ?? 0)],
                'jobs' => ['processed' => (int) ($jobTotals->processed ?? 0), 'failed' => (int) ($jobTotals->failed ?? 0), 'released' => (int) ($jobTotals->released ?? 0),
                    'avg_ms' => isset($jobTotals->total_duration) ? Rollups::avg($jobTotals, 'attempts') : null,
                    'p95_ms' => isset($jobTotals->total_duration) ? Rollups::p95($jobTotals) : null],
                'users' => ['authenticated' => (int) ($userTotal->users ?? 0)],
                'outgoing' => ['calls' => (int) ($outgoingTotals->calls ?? 0), 'errors' => (int) ($outgoingTotals->errors ?? 0),
                    'p95_ms' => isset($outgoingTotals->total_duration) ? Rollups::p95($outgoingTotals) : null],
            ],
            'series' => ['requests' => $requests, 'exceptions' => $exceptions, 'jobs' => $jobs, 'outgoing' => $outgoing, 'users' => $users],
            'threshold_breaches' => $this->thresholdBreaches($window, $environment),
        ];
    }

    /**
     * Open performance issues seen in the window (the agent opens them when a threshold is crossed).
     *
     * @return array{thresholds_configured: int, count: int, items: list<array<string, mixed>>}
     */
    private function thresholdBreaches(Window $window, ?string $environment): array
    {
        $query = Issue::query()->where('type', 'performance')->where('status', 'open')->where('last_seen_at', '>=', $window->fromSql())
            ->when($environment !== null, fn ($q) => $q->where('environment', $environment));

        return [
            'thresholds_configured' => count($this->settings->thresholds()),
            'count' => (clone $query)->count(),
            'items' => $query->orderByDesc('triggered_duration_ms')->limit(10)->get()->map(fn (Issue $i): array => [
                'issue_id' => (int) $i->id, 'subtype' => $i->subtype, 'title' => $i->exception_class, 'threshold_ms' => $i->threshold_ms,
                'triggered_duration_ms' => $i->triggered_duration_ms, 'occurrences' => (int) $i->occurrences_count,
                'last_seen_at' => Raw::iso($i->getRawOriginal('last_seen_at')),
            ])->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function routes(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search, bool $errorsOnly = false): array
    {
        $rows = $this->rollups->grouped('nightowl_request_rollups', $window, $environment, ['group_hash'], [
            'max(route_methods) as route_methods', 'max(route_path) as route_path', 'sum(call_count) as calls',
            'sum(client_error_count) as client_errors', 'sum(server_error_count) as server_errors', ...Rollups::durationSelects(),
        ], $sort === 'calls' ? 'sum(call_count) desc' : 'sum(total_duration) desc', self::CANDIDATES, $this->scopes($this->search($search, 'route_path'), $errorsOnly ? 'sum(client_error_count) + sum(server_error_count) > 0' : null));

        $result = array_map(function (object $r): array {
            $calls = (int) $r->calls;
            $methods = json_decode((string) $r->route_methods, true);

            return [
                'group_hash' => $r->group_hash,
                'method' => is_array($methods) ? implode('|', array_values(array_diff($methods, ['HEAD']))) : $r->route_methods,
                'path' => $r->route_path,
                'threshold_target' => self::routeTarget($methods, $r->route_path),
                'calls' => $calls,
                'status_4xx' => (int) $r->client_errors,
                'status_5xx' => (int) $r->server_errors,
                'rate_4xx' => self::rate((int) $r->client_errors, $calls),
                'rate_5xx' => self::rate((int) $r->server_errors, $calls),
                'avg_ms' => Rollups::avg($r),
                'p95_ms' => Rollups::p95($r),
                'total_ms' => Rollups::ms($r->total_duration),
            ];
        }, $rows);

        return self::sortRows($result, $sort, $direction, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function route(string $groupHash, Window $window, ?string $environment): ?array
    {
        $scope = fn (Builder $q) => $q->where('group_hash', $groupHash);
        $selects = ['max(route_methods) as route_methods', 'max(route_path) as route_path', 'sum(call_count) as calls', 'sum(success_count) as success',
            'sum(client_error_count) as client_errors', 'sum(server_error_count) as server_errors', ...Rollups::durationSelects()];
        $totals = $this->rollups->totals('nightowl_request_rollups', $window, $environment, $selects, $scope);

        if ((int) ($totals->calls ?? 0) === 0 && $this->raw->recentRequests($window, $environment, $groupHash, 1) === []) {
            return null;
        }

        $calls = (int) ($totals->calls ?? 0);
        $methods = json_decode((string) ($totals->route_methods ?? ''), true);

        return [
            'window' => $window->toArray(),
            'route' => [
                'group_hash' => $groupHash,
                'method' => is_array($methods) ? implode('|', array_values(array_diff($methods, ['HEAD']))) : ($totals->route_methods ?? null),
                'path' => $totals->route_path ?? null,
                'threshold_target' => self::routeTarget($methods, $totals->route_path ?? null),
                'calls' => $calls,
                'status_4xx' => (int) ($totals->client_errors ?? 0),
                'status_5xx' => (int) ($totals->server_errors ?? 0),
                'rate_4xx' => self::rate((int) ($totals->client_errors ?? 0), $calls),
                'rate_5xx' => self::rate((int) ($totals->server_errors ?? 0), $calls),
                'avg_ms' => $calls > 0 ? Rollups::avg($totals) : null,
                'p95_ms' => $calls > 0 ? Rollups::p95($totals) : null,
            ],
            'series' => $this->rollups->series('nightowl_request_rollups', $window, $environment, $selects, fn (?object $r): array => [
                'calls' => (int) ($r->calls ?? 0), 'success' => (int) ($r->success ?? 0), 'status_4xx' => (int) ($r->client_errors ?? 0),
                'status_5xx' => (int) ($r->server_errors ?? 0), 'avg_ms' => $r ? Rollups::avg($r) : null, 'p95_ms' => $r ? Rollups::p95($r) : null,
            ], $scope),
            'recent_requests' => $this->raw->recentRequests($window, $environment, $groupHash, 50),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jobs(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search, bool $errorsOnly = false): array
    {
        $rows = $this->rollups->grouped('nightowl_job_rollups', $window, $environment, ['group_hash'], [
            'max(job_class) as job_class', 'max(queue) as queue', 'sum(attempts_count) as attempts', 'sum(queued_count) as queued',
            'sum(processed_count) as processed', 'sum(failed_count) as failed', 'sum(released_count) as released', ...Rollups::durationSelects(),
        ], $sort === 'failed' ? 'sum(failed_count) desc' : 'sum(total_duration) desc', self::CANDIDATES, $this->scopes($this->search($search, 'job_class'), $errorsOnly ? 'sum(failed_count) > 0' : null));

        return self::sortRows(array_map(fn (object $r): array => [
            'group_hash' => $r->group_hash,
            'job' => $r->job_class,
            'queue' => $r->queue,
            'queued' => (int) $r->queued,
            'attempts' => (int) $r->attempts,
            'processed' => (int) $r->processed,
            'failed' => (int) $r->failed,
            'released' => (int) $r->released,
            'failure_rate' => self::rate((int) $r->failed, (int) $r->attempts),
            'avg_ms' => Rollups::avg($r, 'attempts'),
            'p95_ms' => Rollups::p95($r),
            'total_ms' => Rollups::ms($r->total_duration),
        ], $rows), $sort, $direction, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queries(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search, ?string $connection = null): array
    {
        // The SQL text is read only for the rows shown: max(sql_query) over every rollup row compared the long texts
        // of 36k rows and was ~3/4 of the list's time (18.11.2026). A group_hash always has the same text.
        $rows = $this->rollups->grouped('nightowl_query_rollups', $window, $environment, ['group_hash'], [
            'max(connection) as connection', 'sum(call_count) as calls', ...Rollups::durationSelects(),
        ], $sort === 'calls' ? 'sum(call_count) desc' : 'sum(total_duration) desc', self::CANDIDATES, $this->scopes($this->search($search, 'sql_query'), null,
            $connection !== null ? fn (Builder $q) => $q->where('connection', $connection) : null));

        $list = self::sortRows(array_map(fn (object $r): array => [
            'group_hash' => $r->group_hash,
            'sql' => null,
            'connection' => $r->connection,
            'calls' => (int) $r->calls,
            'avg_ms' => Rollups::avg($r),
            'p95_ms' => Rollups::p95($r),
            'max_ms' => Rollups::ms($r->max_duration),
            'total_ms' => Rollups::ms($r->total_duration),
        ], $rows), $sort, $direction, $limit);

        if ($list !== []) {
            // One index probe (group_hash, bucket_start) per shown hash, in one statement: 2 ms for 100 hashes, where
            // DISTINCT ON over the same hashes read every row of the window.
            $hashes = array_column($list, 'group_hash');
            $environmentFilter = $environment !== null ? ' and r.environment = ?' : '';
            $texts = collect($this->rollups->db()->select(
                'select h.hash, (select r.sql_query from '.$window->table('nightowl_query_rollups').' r
                    where r.group_hash = h.hash and r.bucket_start >= ? and r.bucket_start < ? and r.sql_query is not null'.$environmentFilter.'
                    limit 1) as sql_query
                 from unnest(array['.implode(', ', array_fill(0, count($hashes), '?')).']::varchar[]) as h(hash)',
                [$window->fromSql(), $window->toSql(), ...($environment !== null ? [$environment] : []), ...$hashes],
            ))->pluck('sql_query', 'hash');
            foreach ($list as &$row) {
                $row['sql'] = $texts[$row['group_hash']] ?? null;
            }
            unset($row);
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function outgoing(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search, bool $errorsOnly = false): array
    {
        $rows = $this->rollups->grouped('nightowl_outgoing_request_rollups', $window, $environment, ['group_hash'], [
            'max(host) as host', 'sum(call_count) as calls', 'sum(success_count) as success', 'sum(client_error_count) as client_errors',
            'sum(server_error_count) as server_errors', ...Rollups::durationSelects(),
        ], $sort === 'calls' ? 'sum(call_count) desc' : 'sum(total_duration) desc', self::CANDIDATES, $this->scopes($this->search($search, 'host'), $errorsOnly ? 'sum(client_error_count) + sum(server_error_count) > 0' : null));

        return self::sortRows(array_map(fn (object $r): array => [
            'group_hash' => $r->group_hash,
            'host' => $r->host,
            'calls' => (int) $r->calls,
            'status_4xx' => (int) $r->client_errors,
            'status_5xx' => (int) $r->server_errors,
            'error_rate' => self::rate((int) $r->client_errors + (int) $r->server_errors, (int) $r->calls),
            'avg_ms' => Rollups::avg($r),
            'p95_ms' => Rollups::p95($r),
            'total_ms' => Rollups::ms($r->total_duration),
        ], $rows), $sort, $direction, $limit);
    }

    /**
     * @return array{commands: list<array<string, mixed>>, scheduled_tasks: list<array<string, mixed>>}
     */
    public function commands(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search, bool $errorsOnly = false): array
    {
        $commands = $this->rollups->grouped('nightowl_command_rollups', $window, $environment, ['group_hash'], [
            'max(command) as command', 'sum(call_count) as calls', 'sum(successful_count) as successful', 'sum(unsuccessful_count) as failed',
            ...Rollups::durationSelects(),
        ], 'sum(total_duration) desc', self::CANDIDATES, $this->scopes($this->search($search, 'command'), $errorsOnly ? 'sum(unsuccessful_count) > 0' : null));

        $tasks = $this->rollups->grouped('nightowl_scheduled_task_rollups', $window, $environment, ['group_hash'], [
            'max(command) as command', 'max(expression) as expression', 'max(repeat_seconds) as repeat_seconds', 'sum(call_count) as calls',
            'sum(processed_count) as processed', 'sum(failed_count) as failed', 'sum(skipped_count) as skipped', ...Rollups::durationSelects(),
        ], 'sum(total_duration) desc', self::CANDIDATES, $this->scopes($this->search($search, 'command'), $errorsOnly ? 'sum(failed_count) > 0' : null));

        return [
            'commands' => self::sortRows(array_map(fn (object $r): array => [
                'group_hash' => $r->group_hash, 'command' => $r->command, 'calls' => (int) $r->calls, 'successful' => (int) $r->successful,
                'failed' => (int) $r->failed, 'avg_ms' => Rollups::avg($r), 'p95_ms' => Rollups::p95($r), 'total_ms' => Rollups::ms($r->total_duration),
            ], $commands), $sort, $direction, $limit),
            'scheduled_tasks' => self::sortRows(array_map(fn (object $r): array => [
                'group_hash' => $r->group_hash, 'command' => $r->command, 'expression' => $r->expression,
                'repeat_seconds' => $r->repeat_seconds !== null ? (int) $r->repeat_seconds : null, 'next_run_at' => self::nextRun($r->expression), 'calls' => (int) $r->calls,
                'processed' => (int) $r->processed, 'failed' => (int) $r->failed, 'skipped' => (int) $r->skipped,
                'avg_ms' => Rollups::avg($r), 'p95_ms' => Rollups::p95($r), 'total_ms' => Rollups::ms($r->total_duration),
            ], $tasks), $sort, $direction, $limit),
        ];
    }

    /**
     * @return array{mail: list<array<string, mixed>>, notifications: list<array<string, mixed>>}
     */
    public function mail(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search, bool $errorsOnly = false): array
    {
        $mail = $this->rollups->grouped('nightowl_mail_rollups', $window, $environment, ['group_hash'], [
            'max(mailable) as mailable', 'sum(call_count) as calls', 'sum(queued_count) as queued', 'sum(failed_count) as failed', ...Rollups::durationSelects(),
        ], 'sum(call_count) desc', self::CANDIDATES, $this->scopes($this->search($search, 'mailable'), $errorsOnly ? 'sum(failed_count) > 0' : null));

        $notifications = $this->rollups->grouped('nightowl_notification_rollups', $window, $environment, ['group_hash', 'channel'], [
            'max(notification) as notification', 'sum(call_count) as calls', 'sum(queued_count) as queued', 'sum(failed_count) as failed', ...Rollups::durationSelects(),
        ], 'sum(call_count) desc', self::CANDIDATES, $this->scopes($this->search($search, 'notification'), $errorsOnly ? 'sum(failed_count) > 0' : null));

        return [
            'mail' => self::sortRows(array_map(fn (object $r): array => [
                'group_hash' => $r->group_hash, 'mailable' => $r->mailable, 'calls' => (int) $r->calls, 'queued' => (int) $r->queued,
                'failed' => (int) $r->failed, 'avg_ms' => Rollups::avg($r), 'p95_ms' => Rollups::p95($r), 'total_ms' => Rollups::ms($r->total_duration),
            ], $mail), $sort, $direction, $limit),
            'notifications' => self::sortRows(array_map(fn (object $r): array => [
                'group_hash' => $r->group_hash, 'notification' => $r->notification, 'channel' => $r->channel, 'calls' => (int) $r->calls,
                'queued' => (int) $r->queued, 'failed' => (int) $r->failed, 'avg_ms' => Rollups::avg($r), 'p95_ms' => Rollups::p95($r),
                'total_ms' => Rollups::ms($r->total_duration),
            ], $notifications), $sort, $direction, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cache(Window $window, ?string $environment, string $sort, string $direction, int $limit, ?string $search): array
    {
        $selects = ['sum(call_count) as calls', 'sum(hits) as hits', 'sum(misses) as misses', 'sum(writes) as writes', 'sum(deletes) as deletes',
            'sum(fails) + sum(write_failures) + sum(delete_failures) as failures', 'sum(total_duration) as total_duration'];
        $rows = $this->rollups->grouped('nightowl_cache_rollups', $window, $environment, ['key', 'store'], $selects,
            'sum(call_count) desc', self::CANDIDATES, $this->search($search, 'key'));
        $map = fn (object $r): array => [
            'calls' => (int) ($r->calls ?? 0), 'hits' => (int) ($r->hits ?? 0), 'misses' => (int) ($r->misses ?? 0), 'writes' => (int) ($r->writes ?? 0),
            'deletes' => (int) ($r->deletes ?? 0), 'failures' => (int) ($r->failures ?? 0),
            'hit_rate' => self::rate((int) ($r->hits ?? 0), (int) ($r->hits ?? 0) + (int) ($r->misses ?? 0)),
        ];

        return [
            'totals' => $map($this->rollups->totals('nightowl_cache_rollups', $window, $environment, $selects)),
            'series' => $this->rollups->series('nightowl_cache_rollups', $window, $environment, $selects, fn (?object $r): array => $map($r ?? (object) [])),
            'keys' => self::sortRows(array_map(fn (object $r): array => ['key' => $r->key, 'store' => $r->store, ...$map($r),
                'avg_ms' => Rollups::avg($r)], $rows), $sort, $direction, $limit),
        ];
    }

    /**
     * Users by requests and errors; slow requests are counted from the raw table.
     *
     * @return list<array<string, mixed>>
     */
    public function users(Window $window, ?string $environment, string $sort, string $direction, int $limit, int $slowMs): array
    {
        $rows = $this->rollups->grouped('nightowl_user_rollups', $window, $environment, ['user_id'], [
            'sum(call_count) as calls', 'sum(client_error_count) as client_errors', 'sum(server_error_count) as server_errors',
        ], match ($sort) {
            'status_5xx' => 'sum(server_error_count) desc',
            'status_4xx' => 'sum(client_error_count) desc',
            default => 'sum(call_count) desc',
        }, self::CANDIDATES, fn (Builder $q) => $q->where('user_id', '!=', ''));

        $ids = array_map(fn (object $r): string => (string) $r->user_id, $rows);
        $exceptions = $ids === [] ? [] : $this->rollups->query('nightowl_user_exception_rollups', $window, $environment)
            ->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, sum(call_count) as total')->pluck('total', 'user_id')->all();
        $slow = $this->raw->slowRequestsByUser($window, $environment, $ids, $slowMs);
        $names = $this->raw->userNames($ids);

        return self::sortRows(array_map(fn (object $r): array => [
            'user_id' => (string) $r->user_id,
            'name' => $names[(string) $r->user_id]['name'] ?? null,
            'email' => $names[(string) $r->user_id]['email'] ?? null,
            'requests' => (int) $r->calls,
            'status_4xx' => (int) $r->client_errors,
            'status_5xx' => (int) $r->server_errors,
            'exceptions' => (int) ($exceptions[(string) $r->user_id] ?? 0),
            'slow_requests' => (int) ($slow[(string) $r->user_id] ?? 0),
        ], $rows), $sort, $direction, $limit);
    }

    /**
     * One user: who it is, totals and request series from the user rollups, and its latest requests, job attempts,
     * exceptions and logs from the raw tables (the shortcuts of the Nightwatch user page).
     *
     * @return array<string, mixed>|null
     */
    public function user(string $userId, Window $window, ?string $environment): ?array
    {
        $scope = fn (Builder $q) => $q->where('user_id', $userId);
        $selects = ['sum(call_count) as calls', 'sum(client_error_count) as client_errors', 'sum(server_error_count) as server_errors'];
        $totals = $this->rollups->totals('nightowl_user_rollups', $window, $environment, $selects, $scope);
        $exceptions = (int) $this->rollups->query('nightowl_user_exception_rollups', $window, $environment)->where('user_id', $userId)->sum('call_count');
        $jobs = (int) $this->rollups->query('nightowl_user_job_rollups', $window, $environment)->where('user_id', $userId)->sum('attempts_count');
        $names = $this->raw->userNames([$userId]);

        $requests = array_map(fn (object $r): array => ['id' => (int) $r->id, 'created_at' => Raw::iso($r->created_at), 'trace_id' => $r->trace_id,
            'method' => $r->method, 'url' => $r->url, 'status_code' => $r->status_code !== null ? (int) $r->status_code : null, 'duration_ms' => Rollups::ms($r->duration)],
            $this->raw->byUser('nightowl_requests_v2', $userId, $window, $environment, ['trace_id', 'url', 'status_code', 'duration'], ['method_id' => 'method'], 25));

        if ((int) ($totals->calls ?? 0) === 0 && $requests === [] && ! isset($names[$userId])) {
            return null;
        }

        return [
            'window' => $window->toArray(),
            'user' => ['user_id' => $userId, 'name' => $names[$userId]['name'] ?? null, 'email' => $names[$userId]['email'] ?? null],
            'totals' => ['requests' => (int) ($totals->calls ?? 0), 'status_4xx' => (int) ($totals->client_errors ?? 0),
                'status_5xx' => (int) ($totals->server_errors ?? 0), 'exceptions' => $exceptions, 'job_attempts' => $jobs],
            'series' => $this->rollups->series('nightowl_user_rollups', $window, $environment, $selects, fn (?object $r): array => [
                'requests' => (int) ($r->calls ?? 0), 'status_4xx' => (int) ($r->client_errors ?? 0), 'status_5xx' => (int) ($r->server_errors ?? 0)], $scope),
            'requests' => $requests,
            'jobs' => array_map(fn (object $r): array => ['id' => (int) $r->id, 'created_at' => Raw::iso($r->created_at), 'attempt_id' => $r->attempt_id,
                'job' => $r->job_class, 'status' => $r->status, 'duration_ms' => Rollups::ms($r->duration)],
                $this->raw->byUser('nightowl_jobs_v2', $userId, $window, $environment, ['attempt_id', 'duration'], ['job_class_id' => 'job_class', 'status_id' => 'status'], 25)),
            'exceptions' => array_map(fn (object $r): array => ['id' => (int) $r->id, 'created_at' => Raw::iso($r->created_at), 'class' => $r->class,
                'message' => $r->message, 'handled' => (bool) $r->handled, 'fingerprint' => Raw::hex($r->fingerprint), 'execution_id' => $r->execution_id],
                $this->raw->byUser('nightowl_exceptions_v2', $userId, $window, $environment, ['class', 'message', 'handled', 'fingerprint', 'execution_id'], [], 25)),
            'logs' => $this->raw->logs($window, $environment, null, null, 25, 1, $userId)['logs'],
        ];
    }

    /**
     * Calls and durations per bucket of all queries (or one connection), for the Queries charts.
     *
     * @return list<array<string, mixed>>
     */
    public function querySeries(Window $window, ?string $environment, ?string $connection): array
    {
        return $this->rollups->series('nightowl_query_rollups', $window, $environment, ['sum(call_count) as calls', ...Rollups::durationSelects()],
            fn (?object $r): array => ['calls' => (int) ($r->calls ?? 0), ...self::durations($r)],
            $connection !== null && $connection !== '' ? fn (Builder $q) => $q->where('connection', $connection) : null);
    }

    /**
     * Calls by status class and durations per bucket of all outgoing requests, for the Outgoing Requests charts.
     *
     * @return list<array<string, mixed>>
     */
    public function outgoingSeries(Window $window, ?string $environment): array
    {
        return $this->rollups->series('nightowl_outgoing_request_rollups', $window, $environment,
            ['sum(call_count) as calls', 'sum(client_error_count) as client_errors', 'sum(server_error_count) as server_errors', ...Rollups::durationSelects()],
            fn (?object $r): array => ['calls' => (int) ($r->calls ?? 0), 'status_4xx' => (int) ($r->client_errors ?? 0),
                'status_5xx' => (int) ($r->server_errors ?? 0), ...self::durations($r)]);
    }

    /**
     * Runs per bucket of all commands and of all scheduled tasks, for their charts.
     *
     * @return array{commands_series: list<array<string, mixed>>, scheduled_tasks_series: list<array<string, mixed>>}
     */
    public function commandSeries(Window $window, ?string $environment): array
    {
        return [
            'commands_series' => $this->rollups->series('nightowl_command_rollups', $window, $environment,
                ['sum(call_count) as calls', 'sum(successful_count) as successful', 'sum(unsuccessful_count) as failed', ...Rollups::durationSelects()],
                fn (?object $r): array => ['calls' => (int) ($r->calls ?? 0), 'successful' => (int) ($r->successful ?? 0), 'failed' => (int) ($r->failed ?? 0), ...self::durations($r)]),
            'scheduled_tasks_series' => $this->rollups->series('nightowl_scheduled_task_rollups', $window, $environment,
                ['sum(call_count) as calls', 'sum(processed_count) as processed', 'sum(skipped_count) as skipped', 'sum(failed_count) as failed', ...Rollups::durationSelects()],
                fn (?object $r): array => ['calls' => (int) ($r->calls ?? 0), 'processed' => (int) ($r->processed ?? 0), 'skipped' => (int) ($r->skipped ?? 0),
                    'failed' => (int) ($r->failed ?? 0), ...self::durations($r)]),
        ];
    }

    /**
     * Sends per bucket of all mail and of all notifications, for their charts.
     *
     * @return array{mail_series: list<array<string, mixed>>, notifications_series: list<array<string, mixed>>}
     */
    public function mailSeries(Window $window, ?string $environment): array
    {
        $selects = ['sum(call_count) as calls', 'sum(queued_count) as queued', 'sum(failed_count) as failed', ...Rollups::durationSelects()];
        $map = fn (?object $r): array => ['calls' => (int) ($r->calls ?? 0), 'queued' => (int) ($r->queued ?? 0), 'failed' => (int) ($r->failed ?? 0), ...self::durations($r)];

        return [
            'mail_series' => $this->rollups->series('nightowl_mail_rollups', $window, $environment, $selects, $map),
            'notifications_series' => $this->rollups->series('nightowl_notification_rollups', $window, $environment, $selects, $map),
        ];
    }

    /**
     * @return array{avg_ms: ?float, p95_ms: ?float}
     */
    private static function durations(?object $r): array
    {
        $calls = (int) ($r->calls ?? 0);

        return ['avg_ms' => $r !== null && $calls > 0 ? Rollups::avg($r) : null, 'p95_ms' => $r !== null && $calls > 0 ? Rollups::p95($r) : null];
    }

    /**
     * DB connections seen in the window, for the Queries filter pill.
     *
     * @return list<string>
     */
    public function queryConnections(Window $window, ?string $environment): array
    {
        return $this->rollups->query('nightowl_query_rollups', $window, $environment)->whereNotNull('connection')
            ->distinct()->orderBy('connection')->limit(50)->pluck('connection')->all();
    }

    /**
     * Combines optional scopes: a search, a whitelisted HAVING condition (a constant of this class) and a WHERE.
     *
     * @return callable(Builder): void
     */
    private function scopes(?callable $search, ?string $having, ?callable $where = null): callable
    {
        return function (Builder $q) use ($search, $having, $where): void {
            if ($search !== null) {
                $search($q);
            }
            if ($having !== null) {
                $q->havingRaw($having);
            }
            if ($where !== null) {
                $where($q);
            }
        };
    }

    /**
     * Next run of a cron expression in UTC, null for an expression cron cannot parse.
     */
    public static function nextRun(?string $expression): ?string
    {
        try {
            return $expression !== null && $expression !== '' ? (new CronExpression($expression))->getNextRunDate('now', 0, false, 'UTC')->format('Y-m-d\TH:i:s\Z') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A case-insensitive substring filter on a whitelisted column; the term is bound, wildcards escaped.
     *
     * @return callable(Builder): void|null
     */
    private function search(?string $term, string $column): ?callable
    {
        if ($term === null || $term === '') {
            return null;
        }

        return fn (Builder $q) => $q->havingRaw("max({$column}) ilike ?", ['%'.addcslashes($term, '%_\\').'%']);
    }

    /**
     * The agent matches a route threshold on "<methods joined by |> <path>" exactly as it builds it from the record
     * (e.g. "GET|HEAD /api/v1/home"), so the UI offers that string.
     */
    private static function routeTarget(mixed $methods, ?string $path): ?string
    {
        return $path === null ? null : (is_array($methods) && $methods !== [] ? implode('|', $methods).' ' : '').$path;
    }

    private static function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(100 * $part / $whole, 2) : 0.0;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortRows(array $rows, string $key, string $direction, int $limit): array
    {
        usort($rows, function (array $a, array $b) use ($key, $direction): int {
            $cmp = ($a[$key] ?? 0) <=> ($b[$key] ?? 0);

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return array_slice($rows, 0, $limit);
    }
}
