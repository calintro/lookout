<?php

namespace Lookout;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lookout's runtime settings: where it lives, who may open it, which connection reads the NightOwl data
 * and which users issues can be assigned to. Everything app-specific comes from config('lookout') or from the
 * hooks below, set in a service provider of the app.
 */
class Lookout
{
    /** The Gate ability the web app and the Nova menu link check (deny unless the app defines it). */
    public const GATE = 'viewLookout';

    /** @var (Closure(Builder): (Builder|null))|null */
    protected static ?Closure $assignableScope = null;

    public static function path(): string
    {
        return trim((string) config('lookout.path', 'lookout'), '/');
    }

    /**
     * On unless config says otherwise; lookout.enabled = null follows nightowl.enabled at the time of the call.
     */
    public static function enabled(): bool
    {
        $enabled = config('lookout.enabled');

        return (bool) ($enabled ?? config('nightowl.enabled'));
    }

    public static function gate(): string
    {
        return (string) (config('lookout.gate') ?: self::GATE);
    }

    public static function mcpGate(): string
    {
        return (string) (config('lookout.mcp.gate') ?: self::gate());
    }

    public static function connection(): string
    {
        return (string) (config('lookout.connection') ?: 'nightowl_read');
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    public static function userModel(): string
    {
        return (string) (config('lookout.user_model') ?: config('auth.providers.users.model'));
    }

    public static function allows(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows(self::gate());
    }

    public static function allowsMcp(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows(self::mcpGate());
    }

    /**
     * Who may open the app: Lookout on and the gate passed.
     */
    public static function visibleTo(Request $request): bool
    {
        return self::enabled() && self::allows($request->user());
    }

    /**
     * Narrows the users issues can be assigned to, e.g. fn ($query) => $query->where('role', 'admin'). Without it
     * every user that passes the gate is assignable (checked one by one, fine for small user tables).
     *
     * @param  (Closure(Builder): (Builder|null))|null  $scope
     */
    public static function assignableUsersUsing(?Closure $scope): void
    {
        static::$assignableScope = $scope;
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function assignableUsers(int $limit = 200): array
    {
        $query = (self::userModel())::query()->orderBy('name');

        if (static::$assignableScope !== null) {
            $users = ((static::$assignableScope)($query) ?? $query)->limit($limit)->get(['id', 'name', 'email']);
        } else {
            $users = $query->lazy(500)->filter(fn (Model $user): bool => self::allows($user))->take($limit)->values();
        }

        return $users->map(fn (Model $u): array => ['id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all();
    }

    public static function isAssignable(int $userId): bool
    {
        $query = (self::userModel())::query()->whereKey($userId);

        if (static::$assignableScope !== null) {
            return ((static::$assignableScope)($query) ?? $query)->exists();
        }

        $user = $query->first();

        return $user instanceof Authenticatable && self::allows($user);
    }
}
