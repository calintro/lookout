<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Lookout\Services\Settings;

/**
 * Sets one performance threshold, with the web API's rules (Settings::thresholdRules()).
 */
#[IsIdempotent]
class SetThresholdTool extends WriteTool
{
    protected string $name = 'set_threshold';

    protected string $description = <<<'MARKDOWN'
        Set a performance threshold: executions of this type (and target) slower than duration_ms open a performance issue. Without a target it is the type's global rule; a target (e.g. "GET /api/orders" for a route, a job class) wins over it. Replaces the threshold of the same type and target. The agent picks it up within about 30 seconds.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(Settings::THRESHOLD_TYPES)->description('What the threshold applies to.')->required(),
            'target' => $schema->string()->nullable()->description('Route ("GET /path"), job or command class, etc.; empty or null for every target of the type.'),
            'duration_ms' => $schema->integer()->description('Limit in milliseconds, 1-3600000.')->required(),
        ];
    }

    public function handle(Request $request, Settings $settings): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $validated = $request->validate(Settings::thresholdRules());
        $result = $settings->putThreshold($validated['type'], $validated['target'] ?? null, (int) $validated['duration_ms']);
        self::logSettingsChange($admin, $result['replaced'] ? 'threshold changed' : 'threshold added', $result['threshold']);

        return Response::structured([...$result, 'thresholds' => $settings->thresholds()]);
    }
}
