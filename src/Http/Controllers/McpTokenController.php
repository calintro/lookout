<?php

namespace Lookout\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Lookout\Models\McpToken;

/**
 * The signed-in user's own Lookout MCP tokens and the connection settings of the Lookout MCP server.
 */
class McpTokenController extends Controller
{
    /** Active tokens one admin may hold. */
    public const MAX_ACTIVE = 20;

    public function config(): JsonResponse
    {
        $endpoint = rtrim((string) config('app.url'), '/').'/'.trim((string) config('lookout.mcp.path', 'mcp/lookout'), '/');
        $server = self::clientName();

        return response()->json([
            'endpoint' => $endpoint,
            'transport' => 'http',
            'auth_header' => 'Authorization: Bearer <token>',
            'server_name' => $server,
            'claude_code_command' => "claude mcp add --transport http {$server} {$endpoint} --header \"Authorization: Bearer <token>\"",
        ]);
    }

    /**
     * The name the snippets give the server in MCP clients: lookout.mcp.client_name, else "<app>-lookout".
     */
    public static function clientName(): string
    {
        $name = (string) config('lookout.mcp.client_name');

        return $name !== '' ? $name : Str::slug((string) config('app.name')).'-lookout';
    }

    public function index(Request $request): JsonResponse
    {
        $tokens = McpToken::query()->where('user_id', $request->user()->id)->latest('id')->limit(100)->get();

        return response()->json(['tokens' => $tokens->map(fn (McpToken $t): array => self::present($t))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'min:1', 'max:100'], 'can_resolve' => ['sometimes', 'boolean']]);
        $active = McpToken::query()->where('user_id', $request->user()->id)->whereNull('revoked_at')->count();

        if ($active >= self::MAX_ACTIVE) {
            return response()->json(['message' => 'Revoke an old token first: at most '.self::MAX_ACTIVE.' active tokens.', 'errors' => ['name' => ['Too many active tokens.']]], 422);
        }

        ['token' => $token, 'plain_token' => $plain] = McpToken::issue($request->user(), trim($validated['name']), (bool) ($validated['can_resolve'] ?? false));

        return response()->json(['token' => self::present($token->fresh()), 'plain_token' => $plain], 201);
    }

    /**
     * Turns the write right (resolve_issue) of one of your own tokens on or off.
     */
    public function update(Request $request, int $token): JsonResponse
    {
        $validated = $request->validate(['can_resolve' => ['required', 'boolean']]);
        $model = McpToken::query()->where('user_id', $request->user()->id)->whereNull('revoked_at')->findOrFail($token);
        $model->forceFill(['can_resolve' => (bool) $validated['can_resolve']])->save();

        return response()->json(['token' => self::present($model->fresh())]);
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $model = McpToken::query()->where('user_id', $request->user()->id)->findOrFail($token);
        $model->revoke();

        return response()->json(['token' => self::present($model->fresh())]);
    }

    /**
     * @return array{id: int, name: string, prefix: string, can_resolve: bool, last_used_at: ?string, revoked_at: ?string, created_at: ?string}
     */
    private static function present(McpToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'prefix' => $token->prefix,
            'can_resolve' => (bool) $token->can_resolve,
            'last_used_at' => $token->last_used_at?->toIso8601ZuluString(),
            'revoked_at' => $token->revoked_at?->toIso8601ZuluString(),
            'created_at' => $token->created_at?->toIso8601ZuluString(),
        ];
    }
}
