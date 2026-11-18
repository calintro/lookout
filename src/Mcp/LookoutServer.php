<?php

namespace Lookout\Mcp;

use Laravel\Mcp\Server;
use Lookout\Mcp\Tools\FailedJobsTool;
use Lookout\Mcp\Tools\GetIssueTool;
use Lookout\Mcp\Tools\ListIssuesTool;
use Lookout\Mcp\Tools\ResolveIssueTool;
use Lookout\Mcp\Tools\SlowQueriesTool;
use Lookout\Mcp\Tools\SlowRoutesTool;

/**
 * Admin-only MCP server over the self-hosted NightOwl monitoring data, mounted at lookout.mcp.path behind
 * AuthenticateMcpToken. Name and instructions come from config('lookout.mcp').
 */
class LookoutServer extends Server
{
    protected string $name = 'Lookout (admin)';

    protected string $version = '1.0.0';

    protected array $tools = [
        ListIssuesTool::class,
        GetIssueTool::class,
        SlowRoutesTool::class,
        SlowQueriesTool::class,
        FailedJobsTool::class,
        ResolveIssueTool::class,
    ];

    public const INSTRUCTIONS = <<<'MARKDOWN'
        Internal :app application monitoring (self-hosted NightOwl): errors grouped into issues, slow routes, slow queries and failed jobs.
        Start with list_issues, then get_issue for the stack trace. resolve_issue is the only tool that changes anything. Times are UTC, durations in milliseconds.
        MARKDOWN;

    protected string $instructions = self::INSTRUCTIONS;

    protected function boot(): void
    {
        $app = (string) (config('lookout.mcp.app_name') ?: config('app.name'));
        $this->name = (string) (config('lookout.mcp.server_name') ?: trim($app.' Lookout (admin)'));
        $this->instructions = str_replace(':app', $app, self::INSTRUCTIONS);
    }
}
