<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Lookout\Services\Settings;
use Lookout\Services\TelegramChannels;

class CreateAlertChannelTool extends AlertChannelTool
{
    protected string $name = 'create_alert_channel';

    protected string $description = <<<'MARKDOWN'
        Add an alert channel for new and reopened issues. webhook, slack, discord: give url (slack: https://hooks.slack.com/..., discord: https://discord.com/api/webhooks/..., webhook: any public https URL) and for webhook an optional signing secret. telegram: give bot_token, chat_id and an optional message_thread_id. URLs, secrets and tokens are write-only: the answer shows the channel as get_settings does.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(self::TYPES)->required(),
            ...$this->channelSchema($schema, true),
        ];
    }

    public function handle(Request $request, Settings $settings, TelegramChannels $telegram): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $type = $request->validate(['type' => ['required', Rule::in(self::TYPES)]])['type'];
        if (($problem = self::misplaced($request, $type)) !== null) {
            return Response::error($problem);
        }

        $data = self::validated($request, $type, true);
        $channel = $type === 'telegram' ? $telegram->create($data) : $settings->createChannel([...$data, 'type' => $type]);
        self::logSettingsChange($admin, 'alert channel created', ['channel_id' => $channel['id'], 'type' => $type, 'name' => $channel['name'], 'fields' => self::givenFields($request)]);

        return Response::structured(['channel' => $channel]);
    }
}
