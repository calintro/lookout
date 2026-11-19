<?php

namespace Lookout\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lookout\Models\Issue;
use Lookout\Models\IssueComment;

/**
 * Edits of a NightOwl issue other than the status: priority, assignee (an admin's user id, as the agent's
 * assigned_to string), the markdown description and comments. Every change writes one nightowl_issue_activity row
 * (actor_type user, actor_meta.via), like IssueStatus does for status changes.
 */
class IssueEditor
{
    public const ACTIONS = ['priority' => 'priority_changed', 'assigned_to' => 'assigned', 'description' => 'description_changed'];

    /**
     * A comment's body, shared by the web API and MCP (comment_issue).
     *
     * @return array<string, mixed>
     */
    public static function commentRules(): array
    {
        return ['body' => ['required', 'string', 'max:10000']];
    }

    public function __construct(private IssueStatus $status) {}

    /**
     * Applies the given fields (priority, assigned_to, description, status). Returns the names of the fields that changed.
     *
     * @param  array{priority?: ?string, assigned_to?: int|string|null, description?: ?string, status?: string}  $changes
     * @return list<string>
     */
    public function update(Issue $issue, array $changes, ?Authenticatable $actor, string $via = 'monitoring'): array
    {
        $changed = [];

        if (array_key_exists('status', $changes) && $this->status->change($issue, $changes['status'], $actor, $via)) {
            $changed[] = 'status';
        }

        $fields = array_intersect_key($changes, self::ACTIONS);
        if (array_key_exists('assigned_to', $fields)) {
            $fields['assigned_to'] = $fields['assigned_to'] !== null ? (string) $fields['assigned_to'] : null;
        }
        if ($fields === []) {
            return $changed;
        }

        return [...$changed, ...DB::connection($issue->getConnectionName())->transaction(function () use ($issue, $fields, $actor, $via): array {
            $current = (array) $issue->newQuery()->whereKey($issue->getKey())->lockForUpdate()->toBase()->first(array_keys($fields));
            $now = now('UTC')->format('Y-m-d H:i:s');
            $updates = [];
            $activity = [];

            foreach ($fields as $field => $value) {
                if (($current[$field] ?? null) === $value) {
                    continue;
                }
                $updates[$field] = $value;
                $activity[] = $this->activityRow($issue, $actor, $via, self::ACTIONS[$field],
                    $field === 'description' ? null : ($current[$field] ?? null),
                    $field === 'description' ? ($value === null ? null : Str::limit($value, 250)) : $value, $now);
            }

            if ($updates !== []) {
                $issue->newQuery()->whereKey($issue->getKey())->update([...$updates, 'updated_at' => $now]);
                $issue->activity()->getRelated()->newQuery()->insert($activity);
                $issue->forceFill($updates)->syncOriginal();
            }

            return array_keys($updates);
        })];
    }

    public function comment(Issue $issue, Authenticatable $actor, string $body, string $via = 'monitoring'): IssueComment
    {
        return DB::connection($issue->getConnectionName())->transaction(function () use ($issue, $actor, $body, $via): IssueComment {
            $now = now('UTC')->format('Y-m-d H:i:s');
            $id = $issue->comments()->getRelated()->newQuery()->insertGetId([
                'issue_id' => $issue->getKey(),
                'user_id' => (string) $actor->getKey(),
                'user_name' => $actor->name,
                'user_email' => $actor->email,
                'body' => $body,
                // The SPA marks comments written through MCP by this actor type; activity rows carry it in actor_meta.via.
                'actor_type' => $via === 'mcp' ? 'mcp' : 'user',
                'actor_meta' => json_encode(['via' => $via]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $issue->activity()->getRelated()->newQuery()->insert($this->activityRow($issue, $actor, $via, 'commented', null, Str::limit($body, 250), $now));

            return IssueComment::query()->findOrFail($id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRow(Issue $issue, ?Authenticatable $actor, string $via, string $action, ?string $old, ?string $new, string $now): array
    {
        return [
            'issue_id' => $issue->getKey(),
            'user_id' => $actor !== null ? (string) $actor->getKey() : null,
            'user_name' => $actor?->name,
            'actor_type' => 'user',
            'actor_meta' => json_encode(['via' => $via]),
            'action' => $action,
            'old_value' => $old !== null ? Str::limit($old, 250) : null,
            'new_value' => $new,
            'created_at' => $now,
        ];
    }
}
