<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Lookout\Services\Settings;
use Lookout\Services\TelegramChannels;

#[IsIdempotent]
class UpdateAlertChannelTool extends AlertChannelTool
{
    protected string $name = 'update_alert_channel';

    protected string $description = <<<'MARKDOWN'
        Change an alert channel: give its id and type as get_settings shows them, and only the fields to change. Fields left out keep their value, so a stored URL, secret or bot token need not be sent again.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Channel id from get_settings.')->required(),
            'type' => $schema->string()->enum(self::TYPES)->description('The channel\'s type; Telegram channels have their own ids.')->required(),
            ...$this->channelSchema($schema, false),
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
        $exists = $type === 'telegram'
            ? $telegram->find($id) !== null
            : $settings->db()->table('nightowl_alert_channels')->where('id', $id)->value('type') === $type;
        if (! $exists) {
            return Response::error("No {$type} channel with this id.");
        }
        if (($problem = self::misplaced($request, $type)) !== null) {
            return Response::error($problem);
        }

        $data = self::validated($request, $type, false);
        if ($data === []) {
            return Response::error('Nothing to change.');
        }
        $channel = $type === 'telegram' ? $telegram->update($id, $data) : $settings->updateChannel($id, $data);
        self::logSettingsChange($admin, 'alert channel updated', ['channel_id' => $id, 'type' => $type, 'fields' => self::givenFields($request)]);

        return Response::structured(['channel' => $channel]);
    }
}
