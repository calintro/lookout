<?php

namespace Lookout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lookout\Lookout;

/**
 * A comment on a NightOwl issue (the agent's nightowl_issue_comments table; its schema belongs to the package).
 */
class IssueComment extends Model
{
    protected $table = 'nightowl_issue_comments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['actor_meta' => 'array'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function getConnectionName(): ?string
    {
        return Lookout::connection();
    }
}
