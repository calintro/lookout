<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A tool that changes monitoring: issues or settings. It is registered (listed in tools/list, callable) only for a
 * credential with write access (AuthenticateMcpToken sets mcp_can_write); Lookout\Mcp\CallTool answers a read-only
 * credential that calls one anyway with a clear error, and handle() checks again through writer().
 */
abstract class WriteTool extends LookoutTool
{
    public const READ_ONLY = 'This credential is read-only. Turn on "Write access" for the token on the MCP page in Lookout, or, for the shared LOOKOUT_MCP_TOKEN, set LOOKOUT_MCP_TOKEN_CAN_WRITE=true.';

    public function shouldRegister(): bool
    {
        return self::canWrite();
    }

    /**
     * Whether the current MCP credential may write.
     */
    public static function canWrite(): bool
    {
        return request()->attributes->get('mcp_can_write') === true;
    }

    /**
     * The acting admin, or the error to answer with.
     */
    protected function writer(Request $request): Authenticatable|Response
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return Response::error('Admins only.');
        }

        return self::canWrite() ? $admin : Response::error(self::READ_ONLY);
    }

    /**
     * Logs a settings change: who, through MCP, and what. $details must hold no secret (bot tokens, URLs, signing
     * secrets): name the changed fields instead.
     *
     * @param  array<string, mixed>  $details
     */
    protected static function logSettingsChange(Authenticatable $user, string $change, array $details): void
    {
        Log::info("Lookout MCP: {$change}", [
            'user_id' => $user->getAuthIdentifier(),
            'user_name' => $user->name ?? null,
            'via' => 'mcp',
            'auth' => request()->attributes->get('mcp_auth'),
            ...$details,
        ]);
    }
}
