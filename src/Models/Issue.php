<?php

namespace Lookout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lookout\Lookout;

/**
 * An issue the self-hosted NightOwl agent groups from exceptions and slow executions (group_hash, type,
 * environment). The agent owns the table and its schema (nightowl/agent migrations, not database/migrations);
 * the app reads it and edits status, priority, assignee and description (IssueStatus, IssueEditor).
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string|null $group_hash
 */
class Issue extends Model
{
    public const STATUSES = ['open', 'resolved', 'ignored'];

    /** Priorities as the Lookout UI sets them; null means none. */
    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $table = 'nightowl_issues';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'occurrences_count' => 'integer',
            'users_count' => 'integer',
            'threshold_ms' => 'integer',
            'triggered_duration_ms' => 'integer',
        ];
    }

    public function activity(): HasMany
    {
        return $this->hasMany(IssueActivity::class, 'issue_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class, 'issue_id');
    }

    public function getConnectionName(): ?string
    {
        return Lookout::connection();
    }
}
