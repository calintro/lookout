<?php

namespace Lookout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lookout\Lookout;

/**
 * Activity log of a NightOwl issue. The agent reads the last status_changed -> resolved row to decide when a
 * resolved issue may reopen, so every status change the app makes writes one (IssueStatus).
 */
class IssueActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'nightowl_issue_activity';

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
