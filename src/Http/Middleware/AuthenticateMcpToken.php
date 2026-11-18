<?php

namespace Lookout\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Lookout\Lookout;
use Lookout\Models\McpToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate of the Lookout MCP server. Two credentials open it: a personal token a user created in Lookout
 * (lookout.mcp.tokens_table; acts as its owner while the owner still passes the MCP gate), or the shared
 * lookout.mcp.token, which acts as the user lookout.mcp.user_id. Other credentials of the app (API keys, OAuth
 * tokens) never do. The request attribute mcp_can_resolve tells write tools whether the credential may change
 * issues: a personal token only with can_resolve, the shared token always.
 */
class AuthenticateMcpToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) ($request->header('X-Api-Token') ?? $request->bearerToken());

        if ($given !== '' && ($personal = McpToken::findActive($given)) !== null) {
            if (! Lookout::allowsMcp($personal->user)) {
                return response()->json(['error' => 'forbidden', 'message' => 'The owner of this monitoring token is no longer an admin.'], 403);
            }

            $personal->touchLastUsed();

            return $this->actAs($request, $next, $personal->user, 'personal_monitoring_token', $personal->can_resolve);
        }

        $expected = (string) config('lookout.mcp.token');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
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
