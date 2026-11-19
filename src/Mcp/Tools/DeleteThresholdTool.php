<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Lookout\Services\Settings;

#[IsDestructive]
class DeleteThresholdTool extends WriteTool
{
    protected string $name = 'delete_threshold';

    protected string $description = <<<'MARKDOWN'
        Delete the performance threshold of a type and target (no target: the type's global rule).
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(Settings::THRESHOLD_TYPES)->required(),
            'target' => $schema->string()->nullable()->description('The target as get_settings shows it; empty or null for the global rule.'),
        ];
    }

    public function handle(Request $request, Settings $settings): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $validated = $request->validate(['type' => ['required', Rule::in(Settings::THRESHOLD_TYPES)], 'target' => ['nullable', 'string', 'max:500']]);
        $target = $validated['target'] ?? null;
        if (! $settings->deleteThreshold($validated['type'], $target)) {
            return Response::error('No threshold for this type and target.');
        }
        self::logSettingsChange($admin, 'threshold deleted', ['type' => $validated['type'], 'target' => $target]);

        return Response::structured(['deleted' => true, 'thresholds' => $settings->thresholds()]);
    }
}
