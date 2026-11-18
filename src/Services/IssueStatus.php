<?php

namespace Lookout\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lookout\Models\Issue;

/**
 * The one write the app makes to the NightOwl database: an issue's status, with the activity row the agent
 * expects next to it (status_changed, old -> new, actor_type user), the same shape the agent writes on reopen.
 */
class IssueStatus
{
    /**
     * Sets the status and logs it. Returns false when the issue already had it (nothing written).
     */
    public function change(Issue $issue, string $status, ?Authenticatable $actor, string $via = 'nova'): bool
    {
        if (! in_array($status, Issue::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown NightOwl issue status: {$status}");
        }

        return DB::connection($issue->getConnectionName())->transaction(function () use ($issue, $status, $actor, $via): bool {
            // Lock and re-read: the agent may have reopened the issue since this model was loaded.
            $old = $issue->newQuery()->whereKey($issue->getKey())->lockForUpdate()->value('status');
            if ($old === $status) {
                $issue->status = $status;
                $issue->syncOriginalAttribute('status');

                return false;
            }
            $now = now('UTC')->format('Y-m-d H:i:s');

            $issue->newQuery()->whereKey($issue->getKey())->update(['status' => $status, 'updated_at' => $now]);

            $issue->activity()->getRelated()->newQuery()->insert([
                'issue_id' => $issue->getKey(),
                'user_id' => $actor !== null ? (string) $actor->getKey() : null,
                'user_name' => $actor?->name,
                'actor_type' => 'user',
                'actor_meta' => json_encode(['via' => $via]),
                'action' => 'status_changed',
                'old_value' => $old,
                'new_value' => $status,
                'created_at' => $now,
            ]);

            $issue->status = $status;
            $issue->syncOriginalAttribute('status');

            return true;
        });
    }
}
