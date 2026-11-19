<?php

namespace Lookout\Services;

use Lookout\Contracts\SettingStore;

/**
 * How far lookout:telegram-notify got for each Telegram channel, kept in the SettingStore under KEY.{scope} (scope =
 * the environment whose issues are sent, '*' for every one) as {channel id: {at, sent, channel}}:
 *
 * - at: a UTC "Y-m-d H:i:s" watermark; every event of the channel up to it was delivered or summed up.
 * - sent: event key => event time for events after the watermark that were already handled, so re-reading the
 *   window after the watermark (the overlap, or the rest of a run that failed half way) sends nothing twice.
 * - channel: the channel's created_at, so a stale entry is never taken over by a later channel with the same id.
 *
 * The first read of a scope takes over the watermark the command kept for all channels together before 0.2.1
 * (LEGACY_KEY.{scope}), so an update neither resends history nor loses the position. A channel without an entry
 * starts at "now": it gets no history.
 */
class TelegramProgress
{
    public const KEY = 'monitoring.telegram_progress';

    /** The single watermark of every channel before 0.2.1; read once per scope to migrate. */
    public const LEGACY_KEY = 'monitoring.telegram_watermark';

    public function __construct(private SettingStore $store) {}

    /**
     * The environment whose issues are sent: lookout.telegram.environment, else this app's; null = every one.
     */
    public static function scope(): ?string
    {
        $configured = config('lookout.telegram.environment');
        if ($configured === '*') {
            return null;
        }
        $environment = $configured ?: (config('nightowl.environment') ?: config('app.env'));

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    public static function key(?string $scope): string
    {
        return self::KEY.'.'.($scope ?? '*');
    }

    /**
     * Whether the per-channel progress of this scope was ever written.
     */
    public function exists(?string $scope): bool
    {
        return is_array($this->store->get(self::key($scope)));
    }

    /**
     * Writes the first progress of a scope: every channel at the legacy shared watermark if there is one, else at
     * $now. Returns whether the legacy watermark was taken over.
     *
     * @param  list<array<string, mixed>>  $channels
     */
    public function migrate(?string $scope, array $channels, string $now): bool
    {
        $legacy = $this->store->get(self::LEGACY_KEY.'.'.($scope ?? '*'));
        $taken = self::isStamp($legacy) && $legacy <= $now;
        $entries = [];
        foreach ($channels as $channel) {
            $entries[(int) $channel['id']] = self::entry($taken ? $legacy : $now, $channel);
        }
        $this->write($scope, $entries);

        return $taken;
    }

    /**
     * The progress of each given channel; a channel without a (valid) entry starts at $now.
     *
     * @param  list<array<string, mixed>>  $channels
     * @return array<int, array{at: string, sent: array<string, string>, channel: ?string}>
     */
    public function load(?string $scope, array $channels, string $now): array
    {
        $stored = $this->stored($scope);
        $progress = [];
        foreach ($channels as $channel) {
            $id = (int) $channel['id'];
            $entry = $stored[$id] ?? null;
            $progress[$id] = self::valid($entry, $channel)
                ? ['at' => $entry['at'], 'sent' => array_filter((array) ($entry['sent'] ?? []), 'is_string'), 'channel' => $entry['channel'] ?? null]
                : self::entry($now, $channel);
        }

        return $progress;
    }

    /**
     * Stores these entries and drops the entries of channels that no longer exist.
     *
     * @param  array<int, array{at: string, sent: array<string, string>, channel: ?string}>  $entries
     * @param  list<int>  $liveIds
     */
    public function save(?string $scope, array $entries, array $liveIds): void
    {
        $stored = array_intersect_key($this->stored($scope) ?? [], array_flip($liveIds));
        foreach ($entries as $id => $entry) {
            $stored[$id] = $entry;
        }
        $this->write($scope, $stored);
    }

    /**
     * A new channel starts now in the current scope. A scope never written yet is left alone: its first read starts
     * every channel there.
     *
     * @param  array<string, mixed>  $channel
     */
    public function start(array $channel): void
    {
        $scope = self::scope();
        $stored = $this->stored($scope);
        if ($stored !== null) {
            $stored[(int) $channel['id']] = self::entry(now('UTC')->format('Y-m-d H:i:s'), $channel);
            $this->write($scope, $stored);
        }
    }

    /**
     * Drops a deleted channel's progress in the current scope and in '*'.
     */
    public function forget(int $id): void
    {
        foreach (array_unique([self::scope() ?? '*', '*']) as $name) {
            $scope = $name === '*' ? null : $name;
            $stored = $this->stored($scope);
            if ($stored !== null && array_key_exists($id, $stored)) {
                unset($stored[$id]);
                $this->write($scope, $stored);
            }
        }
    }

    public static function isStamp(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $channel
     * @return array{at: string, sent: array<string, string>, channel: ?string}
     */
    private static function entry(string $at, array $channel): array
    {
        return ['at' => $at, 'sent' => [], 'channel' => isset($channel['created_at']) ? (string) $channel['created_at'] : null];
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private static function valid(mixed $entry, array $channel): bool
    {
        return is_array($entry) && self::isStamp($entry['at'] ?? null)
            && ($entry['channel'] ?? null) === (isset($channel['created_at']) ? (string) $channel['created_at'] : null);
    }

    /**
     * @return array<int, mixed>|null
     */
    private function stored(?string $scope): ?array
    {
        $value = $this->store->get(self::key($scope));
        if (! is_array($value)) {
            return null;
        }
        $entries = [];
        foreach ((array) ($value['channels'] ?? []) as $id => $entry) {
            $entries[(int) $id] = $entry;
        }

        return $entries;
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    private function write(?string $scope, array $entries): void
    {
        // Ids as string keys, so the JSON is an object whatever the ids are.
        $channels = [];
        foreach ($entries as $id => $entry) {
            $channels[(string) $id] = $entry;
        }
        $this->store->put(self::key($scope), ['channels' => (object) $channels],
            'Monitoring: per Telegram channel, the UTC time up to which lookout:telegram-notify delivered issues, and the events after it already sent.');
    }
}
