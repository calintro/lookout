<?php

namespace Lookout\Services;

use Illuminate\Validation\Rule;
use Lookout\Contracts\SettingStore;

/**
 * Telegram alert channels. The NightOwl agent only sends to the types its AlertNotifier knows (and throws on any
 * other row in nightowl_alert_channels), so these live in Lookout's SettingStore under KEY as a list of
 * {id, name, bot_token, chat_id, message_thread_id, enabled, notify_events, created_at, updated_at}, and
 * lookout:telegram-notify delivers them. The bot token is a secret: it never leaves the server again after saving.
 * Creating a channel starts its delivery progress (TelegramProgress) at now; deleting one drops it.
 */
class TelegramChannels
{
    public const KEY = 'monitoring.telegram_channels';

    /** A Bot API token as @BotFather issues it: "<bot id>:<secret>". */
    public const TOKEN_PATTERN = '/^\d+:[A-Za-z0-9_-]{30,}$/';

    /** A numeric chat id (negative for groups and channels) or a public @username. */
    public const CHAT_PATTERN = '/^(-?\d+|@[A-Za-z0-9_]{5,})$/';

    /**
     * Validation of a channel, shared by the web API and MCP. An empty bot_token on update keeps the stored one.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'bot_token' => $creating ? ['required', 'string', 'max:200', 'regex:'.self::TOKEN_PATTERN] : ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.self::TOKEN_PATTERN],
            'chat_id' => [$required, 'string', 'max:100', 'regex:'.self::CHAT_PATTERN],
            'message_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
            'notify_events' => ['sometimes', 'nullable', 'array'],
            'notify_events.*' => [Rule::in(Settings::EVENTS)],
        ];
    }

    public function __construct(private SettingStore $store, private TelegramProgress $progress) {}

    /**
     * Every channel as stored, the token included: for the sender only.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $items = $this->store->get(self::KEY);

        return array_values(array_filter(is_array($items) ? $items : [], fn ($c): bool => is_array($c) && isset($c['id'])));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        foreach ($this->all() as $channel) {
            if ((int) $channel['id'] === $id) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, bot_token: string, chat_id: string, message_thread_id?: ?int, enabled?: bool, notify_events?: ?list<string>}  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $channels = $this->all();
        $now = now('UTC')->toIso8601ZuluString();
        $channel = [
            'id' => max([0, ...array_map(fn (array $c): int => (int) $c['id'], $channels)]) + 1,
            'name' => $data['name'],
            'bot_token' => $data['bot_token'],
            'chat_id' => (string) $data['chat_id'],
            'message_thread_id' => isset($data['message_thread_id']) ? (int) $data['message_thread_id'] : null,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'notify_events' => $data['notify_events'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->save([...$channels, $channel]);
        $this->progress->start($channel);

        return self::present($channel);
    }

    /**
     * Keys left out keep their value; an empty bot_token keeps the stored one, so the masked token need not be sent
     * back. A new chat with no new token keeps the token: it identifies the bot, not the chat.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $data): ?array
    {
        $channels = $this->all();
        foreach ($channels as $i => $channel) {
            if ((int) $channel['id'] !== $id) {
                continue;
            }
            $changes = array_intersect_key($data, array_flip(['name', 'chat_id', 'message_thread_id', 'enabled', 'notify_events']));
            if (isset($data['bot_token']) && $data['bot_token'] !== '') {
                $changes['bot_token'] = $data['bot_token'];
            }
            if (array_key_exists('message_thread_id', $changes)) {
                $changes['message_thread_id'] = $changes['message_thread_id'] !== null ? (int) $changes['message_thread_id'] : null;
            }
            if (isset($changes['enabled'])) {
                $changes['enabled'] = (bool) $changes['enabled'];
            }
            $channels[$i] = [...$channel, ...$changes, 'updated_at' => now('UTC')->toIso8601ZuluString()];
            $this->save($channels);

            return self::present($channels[$i]);
        }

        return null;
    }

    public function delete(int $id): bool
    {
        $channels = $this->all();
        $kept = array_values(array_filter($channels, fn (array $c): bool => (int) $c['id'] !== $id));
        if (count($kept) === count($channels)) {
            return false;
        }
        $this->save($kept);
        $this->progress->forget($id);

        return true;
    }

    /**
     * Channels for the UI, in the shape of the agent's channels plus the Telegram fields; the token only masked.
     *
     * @return list<array<string, mixed>>
     */
    public function forUi(): array
    {
        return array_map(fn (array $c): array => self::present($c), $this->all());
    }

    /**
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    public static function present(array $channel): array
    {
        return [
            'id' => (int) $channel['id'],
            'name' => (string) ($channel['name'] ?? ''),
            'type' => 'telegram',
            'enabled' => (bool) ($channel['enabled'] ?? true),
            'url_preview' => null,
            'has_secret' => false,
            'token_preview' => self::maskToken((string) ($channel['bot_token'] ?? '')),
            'chat_id' => (string) ($channel['chat_id'] ?? ''),
            'message_thread_id' => isset($channel['message_thread_id']) ? (int) $channel['message_thread_id'] : null,
            'notify_events' => $channel['notify_events'] ?? null,
            'updated_at' => $channel['updated_at'] ?? null,
        ];
    }

    /**
     * The last four characters behind an ellipsis, enough to tell two bots apart.
     */
    public static function maskToken(string $token): ?string
    {
        return $token === '' ? null : '…'.substr($token, -4);
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    public static function wants(array $channel, string $event): bool
    {
        $events = $channel['notify_events'] ?? null;

        return ($channel['enabled'] ?? true) && ($events === null || in_array($event, (array) $events, true));
    }

    /**
     * @param  list<array<string, mixed>>  $channels
     */
    private function save(array $channels): void
    {
        $this->store->put(self::KEY, array_values($channels),
            'Monitoring: Telegram alert channels (bot token, chat id, events), delivered by lookout:telegram-notify.');
    }
}
