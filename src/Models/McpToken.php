<?php

namespace Lookout\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Lookout\Lookout;

/**
 * A personal token of the Lookout MCP server (lookout.mcp.path). Only its sha256 is stored; the plain token is
 * shown once on creation. It opens the server only while it is not revoked and its owner passes the MCP gate.
 * Write tools also need write access, which is off by default. It is stored in the can_resolve column (its name
 * from before 0.3.0, when resolving was the only write) and shown as can_write.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property bool $can_resolve
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class McpToken extends Model
{
    /** Default start of plain tokens (lookout.mcp.token_prefix), so they are easy to tell apart from other keys. */
    public const PREFIX = 'lookout_';

    protected $fillable = ['user_id', 'name', 'token_hash', 'prefix', 'can_resolve', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'can_resolve' => 'boolean',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * lookout.mcp.tokens_connection, or the default connection.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('lookout.mcp.tokens_connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    public function getTable(): string
    {
        return (string) (config('lookout.mcp.tokens_table') ?: 'lookout_mcp_tokens');
    }

    /**
     * A related model without its own connection stays on the default one: Eloquent would otherwise put the user on
     * lookout.mcp.tokens_connection with the token.
     *
     * @param  class-string<Model>  $class
     */
    protected function newRelatedInstance($class): Model
    {
        return tap(new $class, function (Model $instance): void {
            if (! $instance->getConnectionName()) {
                $instance->setConnection((string) config('database.default'));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Lookout::userModel(), 'user_id');
    }

    /**
     * @return array{token: self, plain_token: string}
     */
    public static function issue(Authenticatable $user, string $name, bool $canWrite = false): array
    {
        $prefix = self::prefix();
        $plain = $prefix.Str::random(48);

        $token = static::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, strlen($prefix) + 4),
            'can_resolve' => $canWrite,
        ]);

        return ['token' => $token, 'plain_token' => $plain];
    }

    /**
     * Whether the token may use the write tools (column can_resolve).
     */
    public function canWrite(): bool
    {
        return (bool) $this->can_resolve;
    }

    /**
     * At most 12 characters: the prefix column keeps it plus 4 characters of the token.
     */
    public static function prefix(): string
    {
        return (string) (config('lookout.mcp.token_prefix') ?: self::PREFIX);
    }

    public static function findActive(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->with('user')
            ->first();
    }

    /**
     * Stamps last_used_at at most once a minute, so a busy client does not write on every call.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
