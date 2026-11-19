<?php

namespace Lookout\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lookout\Contracts\SettingStore;
use Lookout\Lookout;
use Lookout\Services\Telegram;
use Lookout\Services\TelegramChannels;

/**
 * Posts issues that became new or were reopened to the Telegram channels in Lookout settings. Reads the agent's own
 * records: a new issue is a nightowl_issues row created after the watermark, a reopen is the status_changed
 * resolved -> open activity row the agent writes when an issue recurs (the same two events its AlertNotifier sends).
 *
 * The watermark lives in the SettingStore, one per environment scope, and starts at the first run (no history). Each
 * run reads (watermark, now] and moves the watermark to now - OVERLAP_SECONDS, so rows the agent commits a little
 * late are still seen; a cache key per channel and event, set right after each send, keeps the overlap and a crash
 * between sending and saving from posting twice. A failed send stops that channel for the run and is retried while
 * the event is inside the overlap.
 */
class TelegramNotifyCommand extends Command
{
    public const WATERMARK_KEY = 'monitoring.telegram_watermark';

    public const OVERLAP_SECONDS = 120;

    public const DEDUPE_SECONDS = 3600;

    /** Most events of each kind read in one run. */
    public const SCAN_LIMIT = 1000;

    protected $signature = 'lookout:telegram-notify {--limit=20 : Most issue messages per channel in one run; the rest go in one summary}';

    protected $description = 'Post new and reopened NightOwl issues to the Telegram channels in Lookout settings';

    public function handle(TelegramChannels $channels, Telegram $telegram, SettingStore $store): int
    {
        if (! config('lookout.telegram.enabled', true)) {
            $this->info('Telegram alerts are off (lookout.telegram.enabled).');

            return self::SUCCESS;
        }

        $scope = self::environmentScope();
        $key = self::WATERMARK_KEY.'.'.($scope ?? '*');
        $now = CarbonImmutable::now('UTC');
        $upto = $now->format('Y-m-d H:i:s');
        $watermark = $store->get($key);

        if (! is_string($watermark) || preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $watermark) !== 1) {
            $this->saveWatermark($store, $key, $upto);
            $this->info('Started: issues from now on are sent.');

            return self::SUCCESS;
        }

        $targets = array_values(array_filter($channels->all(), fn (array $c): bool => (bool) ($c['enabled'] ?? true)));
        $sent = 0;
        if ($targets !== []) {
            $events = $this->events($watermark, $upto, $scope);
            foreach ($targets as $channel) {
                $sent += $this->deliver($channel, $events, $telegram, $scope);
            }
        }

        // Y-m-d H:i:s strings order like the instants they name.
        $next = max($watermark, $now->subSeconds(self::OVERLAP_SECONDS)->format('Y-m-d H:i:s'));
        if ($next !== $watermark) {
            $this->saveWatermark($store, $key, $next);
        }
        $this->info("Sent {$sent} Telegram message(s).");

        return self::SUCCESS;
    }

    /**
     * The environment whose issues are sent: lookout.telegram.environment, else this app's; null = every one.
     */
    public static function environmentScope(): ?string
    {
        $configured = config('lookout.telegram.environment');
        if ($configured === '*') {
            return null;
        }
        $environment = $configured ?: (config('nightowl.environment') ?: config('app.env'));

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    /**
     * New and reopened issues in (from, upto], oldest first.
     *
     * @return list<array{event: string, key: string, at: string, issue: object}>
     */
    private function events(string $from, string $upto, ?string $scope): array
    {
        $db = DB::connection(Lookout::connection());

        $new = $db->table('nightowl_issues')
            ->where('created_at', '>', $from)->where('created_at', '<=', $upto)
            ->when($scope !== null, fn ($q) => $q->where('environment', $scope))
            ->orderBy('created_at')->orderBy('id')->limit(self::SCAN_LIMIT)->get()
            ->map(fn (object $i): array => ['event' => 'issue.new', 'key' => 'new:'.$i->id, 'at' => (string) $i->created_at, 'issue' => $i]);

        $reopened = $db->table('nightowl_issue_activity as a')->join('nightowl_issues as i', 'i.id', '=', 'a.issue_id')
            ->where('a.action', 'status_changed')->where('a.old_value', 'resolved')->where('a.new_value', 'open')->where('a.actor_type', 'agent')
            ->where('a.created_at', '>', $from)->where('a.created_at', '<=', $upto)
            ->when($scope !== null, fn ($q) => $q->where('i.environment', $scope))
            ->orderBy('a.created_at')->orderBy('a.id')->limit(self::SCAN_LIMIT)
            ->get(['i.*', 'a.id as activity_id', 'a.created_at as event_at'])
            ->map(fn (object $i): array => ['event' => 'issue.reopened', 'key' => "reopened:{$i->id}:{$i->activity_id}", 'at' => (string) $i->event_at, 'issue' => $i]);

        return $new->concat($reopened)->sortBy([['at', 'asc'], fn (array $a, array $b): int => $a['issue']->id <=> $b['issue']->id])->values()->all();
    }

    /**
     * Up to --limit messages to one channel, then one summary for the rest. Returns how many messages went out.
     *
     * @param  array<string, mixed>  $channel
     * @param  list<array{event: string, key: string, at: string, issue: object}>  $events
     */
    private function deliver(array $channel, array $events, Telegram $telegram, ?string $scope): int
    {
        $todo = array_values(array_filter($events, fn (array $ev): bool => TelegramChannels::wants($channel, $ev['event']) && ! Cache::has(self::sentKey($channel, $ev))));
        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;

        foreach (array_slice($todo, 0, $limit) as $ev) {
            if ($telegram->send($channel, $telegram->issueMessage($ev['issue'], $ev['event'])) !== null) {
                return $sent;
            }
            Cache::put(self::sentKey($channel, $ev), true, self::DEDUPE_SECONDS);
            $sent++;
        }

        $rest = array_slice($todo, $limit);
        if ($rest !== [] && $telegram->send($channel, $telegram->summaryMessage(count($rest), $scope)) === null) {
            foreach ($rest as $ev) {
                Cache::put(self::sentKey($channel, $ev), true, self::DEDUPE_SECONDS);
            }
            $sent++;
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $channel
     * @param  array{key: string}  $event
     */
    private static function sentKey(array $channel, array $event): string
    {
        return 'lookout:telegram:'.$channel['id'].':'.substr(md5((string) ($channel['chat_id'] ?? '')), 0, 8).':'.$event['key'];
    }

    private function saveWatermark(SettingStore $store, string $key, string $value): void
    {
        $store->put($key, $value, 'Monitoring: issues created or reopened up to this UTC time were handled by lookout:telegram-notify.');
    }
}
