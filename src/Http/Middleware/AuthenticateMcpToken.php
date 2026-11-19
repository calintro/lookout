<?php

namespace Lookout\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Lookout\Lookout;
use Lookout\Models\McpToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate of the Lookout MCP server. Two credentials open it: a personal token a user created in Lookout
 * (lookout.mcp.tokens_table; acts as its owner while the owner still passes the MCP gate), or the shared
 * lookout.mcp.token, which acts as the user lookout.mcp.user_id. Other credentials of the app (API keys, OAuth
 * tokens) never do. The request attribute mcp_can_resolve tells write tools whether the credential may change
 * issues: a personal token only with can_resolve, the shared token always.
 *
 * Closed (404) while Lookout is disabled. Failed attempts are counted per IP before any token lookup: above
 * lookout.mcp.failed_per_minute the IP gets 429 until the minute is over; successful requests are not counted.
 */
class AuthenticateMcpToken
{
    /** A shared token shorter than this can be guessed; it still works, but is reported once a day. */
    public const MIN_SHARED_TOKEN_LENGTH = 32;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Lookout::enabled(), 404);

        $failures = 'lookout-mcp-auth-failed:'.$request->ip();
        $maxFailures = max(1, (int) config('lookout.mcp.failed_per_minute', 30));

        if (RateLimiter::tooManyAttempts($failures, $maxFailures)) {
            $retryAfter = RateLimiter::availableIn($failures);

            return response()->json(['error' => 'too_many_attempts', 'message' => 'Too many failed attempts. Try again later.'], 429, ['Retry-After' => (string) $retryAfter]);
        }

        $given = (string) ($request->header('X-Api-Token') ?? $request->bearerToken());

        if ($given !== '' && ($personal = McpToken::findActive($given)) !== null) {
            if (! Lookout::allowsMcp($personal->user)) {
                return response()->json(['error' => 'forbidden', 'message' => 'The owner of this monitoring token is no longer an admin.'], 403);
            }

            $personal->touchLastUsed();

            return $this->actAs($request, $next, $personal->user, 'personal_monitoring_token', $personal->can_resolve);
        }

        $expected = (string) config('lookout.mcp.token');
        self::warnAboutShortSharedToken($expected);

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            RateLimiter::hit($failures, 60);

            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'This MCP server needs a monitoring token.',
            ], 401);
        }

        $userId = config('lookout.mcp.user_id');
        $user = $userId === null || $userId === '' ? null : (Lookout::userModel())::query()->find($userId);

        if (! $user instanceof Authenticatable || ! Lookout::allowsMcp($user)) {
            return response()->json(['error' => 'forbidden', 'message' => 'The monitoring MCP user (lookout.mcp.user_id) must be an admin.'], 403);
        }

        return $this->actAs($request, $next, $user, 'monitoring_token', true);
    }

    private static function warnAboutShortSharedToken(string $token): void
    {
        if ($token === '' || strlen($token) >= self::MIN_SHARED_TOKEN_LENGTH || ! Cache::add('lookout-mcp-short-token-warned', true, 86400)) {
            return;
        }

        Log::warning('Lookout: LOOKOUT_MCP_TOKEN is shorter than '.self::MIN_SHARED_TOKEN_LENGTH.' characters and can be guessed. Use a long random value, e.g. php -r "echo bin2hex(random_bytes(32));".');
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    private function actAs(Request $request, Closure $next, Authenticatable $user, string $via, bool $canResolve): Response
    {
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mcp_auth', $via);
        $request->attributes->set('mcp_can_resolve', $canResolve);

        return $next($request);
    }
}
