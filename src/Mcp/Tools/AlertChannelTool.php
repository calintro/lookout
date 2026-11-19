<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Lookout\Services\Settings;
use Lookout\Services\TelegramChannels;

/**
 * Shared argument handling of the alert channel tools. MCP takes flat arguments (url, secret, events, bot_token,
 * chat_id, ...); they are mapped onto the web API's shape and checked with the same rules (Settings::channelRules(),
 * TelegramChannels::rules()), so a URL or token the Settings page refuses is refused here too. Secrets (URLs, signing
 * secrets, bot tokens) are write-only: responses show channels as the web API does, logs name only the fields.
 */
abstract class AlertChannelTool extends WriteTool
{
    public const TYPES = [...Settings::CHANNEL_TYPES, 'telegram'];

    /** Arguments each kind of channel takes besides name, enabled and events. */
    private const FIELDS = [
        'webhook' => ['url', 'secret'],
        'slack' => ['url'],
        'discord' => ['url'],
        'telegram' => ['bot_token', 'chat_id', 'message_thread_id'],
    ];

    private const ALL_FIELDS = ['url', 'secret', 'bot_token', 'chat_id', 'message_thread_id'];

    /** Names in validation messages: the MCP argument, not the stored key. */
    private const ATTRIBUTES = ['config.url' => 'url', 'config.webhook_url' => 'url', 'config.secret' => 'secret',
        'config.notify_events' => 'events', 'config.notify_events.*' => 'events', 'notify_events' => 'events', 'notify_events.*' => 'events'];

    /**
     * @return array<string, mixed>
     */
    protected function channelSchema(JsonSchema $schema, bool $creating): array
    {
        return [
            'name' => $creating ? $schema->string()->description('Channel name, up to 100 characters.')->required() : $schema->string()->description('New name.'),
            'enabled' => $schema->boolean()->description($creating ? 'Default true.' : 'Turn the channel on or off.'),
            'events' => $schema->array()->items($schema->string()->enum(Settings::EVENTS))->nullable()
                ->description('Events to send: issue.new, issue.reopened; null = all.'),
            'url' => $schema->string()->description('webhook, slack, discord: the https webhook URL. Write-only, never shown again.'),
            'secret' => $schema->string()->nullable()->description('webhook: HMAC signing secret. Write-only. A new url without a new secret drops the old one.'),
            'bot_token' => $schema->string()->description('telegram: bot token from @BotFather ("123456789:AA..."). Write-only.'),
            'chat_id' => $schema->string()->description('telegram: numeric chat id (negative for groups and channels) or @channelname.'),
            'message_thread_id' => $schema->integer()->nullable()->description('telegram: forum topic id.'),
        ];
    }

    /**
     * Why these arguments do not fit a channel of this type, or null.
     */
    protected static function misplaced(Request $request, string $type): ?string
    {
        $foreign = array_values(array_filter(array_diff(self::ALL_FIELDS, self::FIELDS[$type] ?? []), fn (string $f): bool => $request->get($f) !== null));

        return $foreign === [] ? null : 'A '.$type.' channel does not take '.implode(', ', $foreign).'.';
    }

    /**
     * Validated data for Settings::createChannel()/updateChannel() (agent types) or TelegramChannels::create()/update().
     *
     * @return array<string, mixed>
     */
    protected static function validated(Request $request, string $type, bool $creating): array
    {
        $args = $request->all();
        $has = fn (string $key): bool => array_key_exists($key, $args);
        $data = array_intersect_key($args, array_flip(['name', 'enabled']));

        if ($type === 'telegram') {
            $data = [...$data, ...array_intersect_key($args, array_flip(['bot_token', 'chat_id', 'message_thread_id']))];
            if ($has('events')) {
                $data['notify_events'] = $args['events'];
            }

            return Validator::make($data, TelegramChannels::rules($creating), [], self::ATTRIBUTES)->validate();
        }

        $config = [];
        if ($has('url')) {
            $config[Settings::urlKey($type)] = $args['url'];
        }
        if ($has('secret')) {
            $config['secret'] = $args['secret'];
        }
        if ($has('events')) {
            $config['notify_events'] = $args['events'];
        }
        if ($creating || $config !== []) {
            $data['config'] = $config;
        }

        return Validator::make($data, Settings::channelRules($type, $creating), [], self::ATTRIBUTES)->validate();
    }

    /**
     * The argument names given, for the log: never their values.
     *
     * @return list<string>
     */
    protected static function givenFields(Request $request): array
    {
        return array_values(array_intersect(['name', 'enabled', 'events', ...self::ALL_FIELDS], array_keys($request->all())));
    }
}
