<?php

namespace Lookout\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Lookout\Services\Settings;

/**
 * The monitoring settings as the web API shows them (Settings::all()): secrets masked, never returned.
 */
#[IsReadOnly]
class GetSettingsTool extends LookoutTool
{
    protected string $name = 'get_settings';

    protected string $description = <<<'MARKDOWN'
        The monitoring settings: performance thresholds (p95 limits per type and target, in ms), alert channels (webhook, Slack, Discord, Telegram; URLs cut to the host, secrets and bot tokens never shown) and auto-resolve days. Channel ids are per type: Telegram channels have their own ids.
    MARKDOWN;

    public function handle(Request $request, Settings $settings): Response|ResponseFactory
    {
        if ($this->admin($request) === null) {
            return Response::error('Admins only.');
        }

        return Response::structured($settings->all());
    }
}
