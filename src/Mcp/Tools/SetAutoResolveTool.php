<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Lookout\Services\Settings;

#[IsIdempotent]
class SetAutoResolveTool extends WriteTool
{
    protected string $name = 'set_auto_resolve';

    protected string $description = <<<'MARKDOWN'
        Resolve open issues automatically once they have not been seen for 7, 14 or 30 days (checked daily). null turns it off.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->enum(Settings::AUTO_RESOLVE_DAYS)->nullable()->description('7, 14, 30, or null for never.')->required(),
        ];
    }

    public function handle(Request $request, Settings $settings): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $validated = $request->validate(Settings::autoResolveRules());
        $old = Settings::autoResolveDays();
        $settings->saveAutoResolveDays($validated['days'] !== null ? (int) $validated['days'] : null);
        self::logSettingsChange($admin, 'auto-resolve changed', ['old_days' => $old, 'days' => Settings::autoResolveDays()]);

        return Response::structured(['auto_resolve_days' => Settings::autoResolveDays()]);
    }
}
