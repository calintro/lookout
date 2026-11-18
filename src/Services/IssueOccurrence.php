<?php

namespace Lookout\Services;

use Illuminate\Support\Facades\DB;
use Lookout\Models\Issue;

/**
 * The latest occurrence of an exception issue, read from the agent's raw tables: nightowl_exceptions_v2 (keyed by
 * the issue's group_hash as the fingerprint) and the stack trace from nightowl_dict_trace, stored gzdeflate'd.
 */
class IssueOccurrence
{
    /**
     * @return array{class: string, message: ?string, file: ?string, line: ?int, handled: bool, created_at: string, php_version: ?string, laravel_version: ?string, trace: list<array{file: ?string, source: ?string}>}|null
     */
    public function latest(Issue $issue): ?array
    {
        if ($issue->type !== 'exception' || ! is_string($issue->group_hash) || preg_match('/^[0-9a-f]{32}$/i', $issue->group_hash) !== 1) {
            return null;
        }

        $db = DB::connection($issue->getConnectionName());

        $row = $db->table('nightowl_exceptions_v2')
            ->whereRaw("fingerprint = decode(?, 'hex')", [strtolower($issue->group_hash)])
            ->orderByDesc('created_at')
            ->first(['class', 'message', 'file', 'line', 'handled', 'created_at', 'trace_ref', 'php_version', 'laravel_version']);

        if ($row === null) {
            return null;
        }

        $blob = $row->trace_ref !== null
            ? $db->table('nightowl_dict_trace')->where('id', $row->trace_ref)->value('trace_z')
            : null;

        return [
            'class' => $row->class,
            'message' => $row->message,
            'file' => $row->file,
            'line' => $row->line !== null ? (int) $row->line : null,
            'handled' => (bool) $row->handled,
            'created_at' => (string) $row->created_at,
            'php_version' => $row->php_version,
            'laravel_version' => $row->laravel_version,
            'trace' => self::decodeTrace($blob),
        ];
    }

    /**
     * A trace_z value as pdo_pgsql returns bytea (a stream, or the raw bytes) -> frames.
     *
     * @return list<array{file: ?string, source: ?string}>
     */
    public static function decodeTrace(mixed $blob): array
    {
        if (is_resource($blob)) {
            $blob = stream_get_contents($blob);
        }

        if (! is_string($blob) || $blob === '') {
            return [];
        }

        $json = @gzinflate($blob);
        $frames = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($frames)) {
            return [];
        }

        return array_values(array_map(fn (mixed $frame): array => [
            'file' => is_array($frame) ? ($frame['file'] ?? null) : null,
            'source' => is_array($frame) && ($frame['source'] ?? '') !== '' ? $frame['source'] : null,
        ], $frames));
    }

    /**
     * @param  list<array{file: ?string, source: ?string}>  $trace
     */
    public static function formatTrace(array $trace): string
    {
        return implode("\n", array_map(
            fn (array $frame, int $i): string => sprintf('#%d %s%s', $i, $frame['file'] ?? '[internal]', $frame['source'] !== null ? '  '.$frame['source'] : ''),
            $trace,
            array_keys($trace),
        ));
    }
}
