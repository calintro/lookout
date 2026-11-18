<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Lookout\Lookout;

/**
 * A tool of the Lookout MCP server. The route's AuthenticateMcpToken middleware already refuses everyone else;
 * the tools check the MCP gate again so they stay closed if one is ever registered on another server.
 */
abstract class LookoutTool extends Tool
{
    protected function admin(Request $request): ?Authenticatable
    {
        $user = request()->user() ?? $request->user();

        return $user instanceof Authenticatable && Lookout::allowsMcp($user) ? $user : null;
    }

    protected static function hours(Request $request): int
    {
        return max(1, min((int) ($request->get('hours') ?? 24), 24 * 400));
    }

    protected static function limit(Request $request, int $default = 20): int
    {
        return max(1, min((int) ($request->get('limit') ?? $default), 100));
    }
}
