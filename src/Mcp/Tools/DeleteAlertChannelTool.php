<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Lookout\Services\Settings;
use Lookout\Services\TelegramChannels;

#[IsDestructive]
class DeleteAlertChannelTool extends AlertChannelTool
{
    protected string $name = 'delete_alert_channel';

    protected string $description = <<<'MARKDOWN'
        Delete an alert channel by its id and type as get_settings shows them.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Channel id from get_settings.')->required(),
            'type' => $schema->string()->enum(self::TYPES)->required(),
        ];
    }

    public function handle(Request $request, Settings $settings, TelegramChannels $telegram): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        ['id' => $id, 'type' => $type] = $request->validate(['id' => ['required', 'integer'], 'type' => ['required', Rule::in(self::TYPES)]]);
        $id = (int) $id;
        $deleted = $type === 'telegram'
            ? $telegram->delete($id)
            : $settings->db()->table('nightowl_alert_channels')->where('id', $id)->where('type', $type)->value('id') !== null && $settings->deleteChannel($id);
        if (! $deleted) {
            return Response::error("No {$type} channel with this id.");
        }
        self::logSettingsChange($admin, 'alert channel deleted', ['channel_id' => $id, 'type' => $type]);

        return Response::structured(['deleted' => true, 'id' => $id, 'type' => $type]);
    }
}
