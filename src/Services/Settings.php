<?php

namespace Lookout\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Lookout\Contracts\SettingStore;
use Lookout\Lookout;

/**
 * Lookout settings, stored where their reader looks:
 * - thresholds: nightowl_settings key "thresholds", a JSON list of {type, target, duration_ms}. The agent's
 *   RecordWriter polls its updated_at every 30 s and opens performance issues for slower executions; an empty
 *   target is the global rule for that type, a specific target wins over it.
 * - alert channels: nightowl_alert_channels (type webhook | slack | discord, JSON config). The agent's
 *   AlertNotifier sends issue.new / issue.reopened to every enabled channel; webhook config is {url, secret?}
 *   (HMAC-SHA256 in X-NightOwl-Signature), slack/discord {webhook_url}; notify_events limits the events.
 * - telegram channels: the agent cannot send them (its AlertNotifier throws on unknown types), so they live in the
 *   bound SettingStore (TelegramChannels) and lookout:telegram-notify (scheduled every minute) delivers them. The UI
 *   gets them in alert_channels with type "telegram"; CHANNEL_TYPES stays the agent's own list.
 * - auto-resolve: the agent has none, so the days live under monitoring.auto_resolve_days in the bound SettingStore
 *   and lookout:auto-resolve (scheduled daily) applies them.
 */
class Settings
{
    public const THRESHOLD_TYPES = ['route', 'job', 'command', 'scheduled_task', 'query', 'cache', 'mail', 'notification', 'outgoing_request'];

    public const CHANNEL_TYPES = ['webhook', 'slack', 'discord'];

    public const EVENTS = ['issue.new', 'issue.reopened'];

    public const AUTO_RESOLVE_KEY = 'monitoring.auto_resolve_days';

    public const AUTO_RESOLVE_DAYS = [7, 14, 30];

    public function db(): ConnectionInterface
    {
        return DB::connection(Lookout::connection());
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'thresholds' => $this->thresholds(),
            'threshold_types' => self::THRESHOLD_TYPES,
            'alert_channels' => [
                ...$this->db()->table('nightowl_alert_channels')->orderBy('id')->get()->map(fn (object $c): array => self::channel($c))->all(),
                ...app(TelegramChannels::class)->forUi(),
            ],
            'channel_types' => [...self::CHANNEL_TYPES, 'telegram'],
            'events' => self::EVENTS,
            'auto_resolve_days' => self::autoResolveDays(),
            'auto_resolve_options' => [null, ...self::AUTO_RESOLVE_DAYS],
        ];
    }

    /**
     * @return list<array{type: string, target: ?string, duration_ms: int}>
     */
    public function thresholds(): array
    {
        $raw = $this->db()->table('nightowl_settings')->where('key', 'thresholds')->value('value');
        $items = is_string($raw) ? json_decode($raw, true) : null;

        return array_values(array_map(fn (array $t): array => [
            'type' => (string) ($t['type'] ?? 'route'),
            'target' => ($t['target'] ?? $t['route'] ?? null) ?: null,
            'duration_ms' => (int) ($t['duration_ms'] ?? 0),
        ], array_filter(is_array($items) ? $items : [], 'is_array')));
    }

    /**
     * Replaces the whole list; updated_at moves, so the agent reloads within ~30 s.
     *
     * @param  list<array{type: string, target: ?string, duration_ms: int}>  $thresholds
     */
    public function saveThresholds(array $thresholds): void
    {
        $thresholds = array_map(fn (array $t): array => [...$t, 'target' => $t['type'] === 'route' ? self::routeTarget($t['target'] ?? null) : ($t['target'] ?? null)], $thresholds);
        $now = now('UTC')->format('Y-m-d H:i:s');
        $this->db()->table('nightowl_settings')->upsert(
            [['key' => 'thresholds', 'value' => json_encode(array_values($thresholds)), 'created_at' => $now, 'updated_at' => $now]],
            ['key'], ['value', 'updated_at'],
        );
    }

    /**
     * A route target as the agent compares it: "<METHODS> <path>", methods upper-cased and joined by "|", HEAD added
     * after GET (Laravel registers both and the agent keys on the full list). A bare path is left as it is.
     */
    public static function routeTarget(?string $target): ?string
    {
        if ($target === null || ! preg_match('#^([A-Za-z|]+)\s+(\S.*)$#', trim($target), $m)) {
            return $target;
        }
        $methods = array_values(array_unique(array_filter(explode('|', strtoupper($m[1])))));
        if (in_array('GET', $methods, true) && ! in_array('HEAD', $methods, true)) {
            array_splice($methods, array_search('GET', $methods, true) + 1, 0, ['HEAD']);
        }

        return implode('|', $methods).' '.$m[2];
    }

    /**
     * @param  array{name: string, type: string, enabled?: bool, config: array<string, mixed>}  $data
     */
    public function createChannel(array $data): array
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        $id = $this->db()->table('nightowl_alert_channels')->insertGetId([
            'name' => $data['name'], 'type' => $data['type'], 'config' => json_encode(self::cleanConfig($data['type'], $data['config'])),
            'enabled' => $data['enabled'] ?? true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return self::channel($this->db()->table('nightowl_alert_channels')->find($id));
    }

    /**
     * Config keys left out keep their stored value, so the masked secret/url need not be sent back.
     *
     * @param  array{name?: string, enabled?: bool, config?: array<string, mixed>}  $data
     */
    public function updateChannel(int $id, array $data): ?array
    {
        $row = $this->db()->table('nightowl_alert_channels')->find($id);
        if ($row === null) {
            return null;
        }

        $config = json_decode((string) $row->config, true) ?: [];
        $updates = array_intersect_key($data, ['name' => 1, 'enabled' => 1]);
        if (isset($data['config'])) {
            // A signing secret belongs to the receiver it was agreed with: a new URL drops it unless a new one is sent,
            // so payloads re-pointed elsewhere are not signed with the old receiver's secret.
            $urlKey = self::urlKey($row->type);
            if (isset($data['config'][$urlKey]) && $data['config'][$urlKey] !== ($config[$urlKey] ?? null) && ! array_key_exists('secret', $data['config'])) {
                unset($config['secret']);
            }
            $updates['config'] = json_encode(self::cleanConfig($row->type, [...$config, ...$data['config']]));
        }
        $this->db()->table('nightowl_alert_channels')->where('id', $id)->update([...$updates, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);

        return self::channel($this->db()->table('nightowl_alert_channels')->find($id));
    }

    public function deleteChannel(int $id): bool
    {
        return $this->db()->table('nightowl_alert_channels')->where('id', $id)->delete() > 0;
    }

    public static function autoResolveDays(): ?int
    {
        $days = app(SettingStore::class)->get(self::AUTO_RESOLVE_KEY);

        return in_array((int) $days, self::AUTO_RESOLVE_DAYS, true) ? (int) $days : null;
    }

    public function saveAutoResolveDays(?int $days): void
    {
        app(SettingStore::class)->put(self::AUTO_RESOLVE_KEY, $days,
            'Monitoring: resolve open NightOwl issues not seen for this many days (7/14/30, null = never). Applied by nightowl:auto-resolve.');
    }

    public static function urlKey(string $type): string
    {
        return $type === 'webhook' ? 'url' : 'webhook_url';
    }

    /**
     * Why a channel URL is refused, or null. Slack and Discord only take their own webhook hosts. A generic webhook may
     * point anywhere public, but not at localhost or a literal private, loopback, link-local or reserved IP.
     *
     * Residual risk: hosts are not resolved here, so a public name that resolves to an internal address (or is
     * re-pointed later, DNS rebinding) still passes. The agent sends the alerts; restrict its egress where it matters.
     */
    public static function channelUrlProblem(string $type, string $url): ?string
    {
        $lower = strtolower($url);

        if ($type === 'slack') {
            return str_starts_with($lower, 'https://hooks.slack.com/') ? null : 'A Slack webhook URL starts with https://hooks.slack.com/.';
        }
        if ($type === 'discord') {
            return str_starts_with($lower, 'https://discord.com/api/webhooks/') || str_starts_with($lower, 'https://discordapp.com/api/webhooks/')
                ? null : 'A Discord webhook URL starts with https://discord.com/api/webhooks/.';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return 'The URL needs a host.';
        }
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return 'The webhook cannot point at localhost.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false
                ? 'The webhook cannot point at a private, loopback or link-local address.' : null;
        }
        // Numeric forms an HTTP client may still read as an IPv4 address (2130706433, 0x7f.1, 127.1).
        if (preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/', $host) === 1) {
            return 'Write the address as a host name or a dotted public IP.';
        }

        return null;
    }

    /**
     * Only the keys the agent reads for this channel type.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function cleanConfig(string $type, array $config): array
    {
        $keys = $type === 'webhook' ? ['url', 'secret', 'notify_events'] : ['webhook_url', 'notify_events'];

        return array_filter(array_intersect_key($config, array_flip($keys)), fn ($v) => $v !== null && $v !== '');
    }

    /**
     * A channel for the UI: URLs cut to scheme + host, the secret never returned.
     *
     * @return array<string, mixed>
     */
    private static function channel(object $row): array
    {
        $config = json_decode((string) $row->config, true) ?: [];
        $url = $config['url'] ?? $config['webhook_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'type' => $row->type,
            'enabled' => (bool) $row->enabled,
            'url_preview' => is_array($parts) && isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/…' : null,
            'has_secret' => ! empty($config['secret']),
            'notify_events' => $config['notify_events'] ?? null,
            'updated_at' => Raw::iso($row->updated_at),
        ];
    }
}
