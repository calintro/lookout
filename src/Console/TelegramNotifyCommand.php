<?php

namespace Lookout\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lookout\Lookout;
use Lookout\Services\Telegram;
use Lookout\Services\TelegramChannels;
use Lookout\Services\TelegramProgress;

/**
 * Posts issues that became new or were reopened to the Telegram channels in Lookout settings. Reads the agent's own
 * records: a new issue is a nightowl_issues row created after the watermark, a reopen is the status_changed
 * resolved -> open activity row the agent writes when an issue recurs (the same two events its AlertNotifier sends).
 *
 * Each channel has its own watermark (TelegramProgress), so one channel that cannot be reached holds back neither the
 * others nor its own events. A run reads (oldest watermark, now] and gives each channel what it has not had yet, oldest
 * first. A channel's watermark moves only over events it was sent (or counted in the "…and N more" message) and never
 * past now - OVERLAP_SECONDS, so rows the agent commits a little late are still seen; the events after the watermark
 * that were already sent are kept with it, so re-reading them sends nothing twice. On a failed send the channel stops
 * for this run and its watermark stays just before the event that failed: the next run retries from there, however
 * long the outage lasts. A cache key per channel and event, set right after each send, keeps a crash between sending
 * and saving from posting twice.
 */
class TelegramNotifyCommand extends Command
{
    /** @deprecated The watermark of all channels together before 0.2.1; see TelegramProgress::LEGACY_KEY. */
    public const WATERMARK_KEY = TelegramProgress::LEGACY_KEY;

    public const OVERLAP_SECONDS = 120;

    /** How long the cache remembers a sent event: longer than any outage a retry is expected to bridge. */
    public const DEDUPE_SECONDS = 7 * 86400;

    /** Most events of each kind read in one run. */
    public const SCAN_LIMIT = 1000;

    protected $signature = 'lookout:telegram-notify {--limit=20 : Most issue messages per channel in one run; the rest go in one summary}';

    protected $description = 'Post new and reopened NightOwl issues to the Telegram channels in Lookout settings';

    public function handle(TelegramChannels $channels, TelegramProgress $progress, Telegram $telegram): int
    {
        if (! config('lookout.telegram.enabled', true)) {
            $this->info('Telegram alerts are off (lookout.telegram.enabled).');

            return self::SUCCESS;
        }

        $scope = self::environmentScope();
        $now = CarbonImmutable::now('UTC');
        $upto = $now->format('Y-m-d H:i:s');
        $all = $channels->all();

        if (! $progress->exists($scope)) {
            $this->info($progress->migrate($scope, $all, $upto)
                ? 'Took over the shared watermark: each channel goes on from there.'
                : 'Started: issues from now on are sent.');
        }
        $entries = $progress->load($scope, $all, $upto);

        $targets = [];
        foreach ($all as $channel) {
            $id = (int) $channel['id'];
            if ((bool) ($channel['enabled'] ?? true)) {
                $targets[] = $channel;
            } else {
                // A disabled channel gets nothing, then or later: once enabled again it goes on from then.
                $entries[$id] = ['at' => max($entries[$id]['at'], $upto), 'sent' => [], 'channel' => $entries[$id]['channel']];
            }
        }

        $sent = 0;
        if ($targets !== []) {
            $from = min(array_map(fn (array $c): string => $entries[(int) $c['id']]['at'], $targets));
            [$events, $horizon] = $this->events($from, $upto, $scope);
            // Y-m-d H:i:s strings order like the instants they name.
            $settled = min($horizon, $now->subSeconds(self::OVERLAP_SECONDS)->format('Y-m-d H:i:s'));
            foreach ($targets as $channel) {
                $id = (int) $channel['id'];
                [$entries[$id], $count] = $this->deliver($channel, $entries[$id], $events, $settled, $telegram, $scope);
                $sent += $count;
            }
        }

        $progress->save($scope, $entries, array_map(fn (array $c): int => (int) $c['id'], $channels->all()));
        $this->info("Sent {$sent} Telegram message(s).");

        return self::SUCCESS;
    }

    /**
     * The environment whose issues are sent: lookout.telegram.environment, else this app's; null = every one.
     */
    public static function environmentScope(): ?string
    {
        return TelegramProgress::scope();
    }

    /**
     * New and reopened issues in (from, upto], oldest first, and how far they reach: upto, or less when a kind hit
     * SCAN_LIMIT (the rest is read by a later run).
     *
     * @return array{0: list<array{event: string, key: string, at: string, issue: object}>, 1: string}
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

        $horizon = $upto;
        foreach ([$new, $reopened] as $kind) {
            if ($kind->count() >= self::SCAN_LIMIT) {
                // Rows in the last second read may go on past the limit: settle only up to the second before it.
                $horizon = min($horizon, self::secondBefore((string) $kind->last()['at']));
            }
        }

        $events = $new->concat($reopened)
            ->sortBy([['at', 'asc'], fn (array $a, array $b): int => $a['issue']->id <=> $b['issue']->id])->values()->all();

        return [$events, $horizon];
    }

    /**
     * Up to --limit messages to one channel, oldest first, then one summary for the rest. Returns the channel's new
     * progress and how many messages went out.
     *
     * @param  array<string, mixed>  $channel
     * @param  array{at: string, sent: array<string, string>, channel: ?string}  $entry
     * @param  list<array{event: string, key: string, at: string, issue: object}>  $events
     * @return array{0: array{at: string, sent: array<string, string>, channel: ?string}, 1: int}
     */
    private function deliver(array $channel, array $entry, array $events, string $settled, Telegram $telegram, ?string $scope): array
    {
        $todo = [];
        foreach ($events as $ev) {
            if ($ev['at'] <= $entry['at'] || isset($entry['sent'][$ev['key']]) || ! TelegramChannels::wants($channel, $ev['event'])) {
                continue;
            }
            if (Cache::has(self::sentKey($channel, $ev))) {
                $entry['sent'][$ev['key']] = $ev['at'];

                continue;
            }
            $todo[] = $ev;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;
        $failed = null;

        foreach (array_slice($todo, 0, $limit) as $ev) {
            if ($telegram->send($channel, $telegram->issueMessage($ev['issue'], $ev['event'])) !== null) {
                $failed = $ev;
                break;
            }
            $this->handled($channel, $entry, $ev);
            $sent++;
        }

        $rest = array_slice($todo, $limit);
        if ($failed === null && $rest !== []) {
            if ($telegram->send($channel, $telegram->summaryMessage(count($rest), $scope)) === null) {
                foreach ($rest as $ev) {
                    $this->handled($channel, $entry, $ev);
                }
                $sent++;
            } else {
                $failed = $rest[0];
            }
        }

        $target = $failed !== null ? min($settled, self::secondBefore($failed['at'])) : $settled;
        $entry['at'] = max($entry['at'], $target);
        $entry['sent'] = array_filter($entry['sent'], fn (string $at): bool => $at > $entry['at']);

        return [$entry, $sent];
    }

    /**
     * @param  array<string, mixed>  $channel
     * @param  array{at: string, sent: array<string, string>, channel: ?string}  $entry
     * @param  array{key: string, at: string}  $event
     */
    private function handled(array $channel, array &$entry, array $event): void
    {
        Cache::put(self::sentKey($channel, $event), true, self::DEDUPE_SECONDS);
        $entry['sent'][$event['key']] = $event['at'];
    }

    /**
     * @param  array<string, mixed>  $channel
     * @param  array{key: string}  $event
     */
    private static function sentKey(array $channel, array $event): string
    {
        return 'lookout:telegram:'.$channel['id'].':'.substr(md5((string) ($channel['chat_id'] ?? '')), 0, 8).':'.$event['key'];
    }

    private static function secondBefore(string $at): string
    {
        return CarbonImmutable::parse($at, 'UTC')->startOfSecond()->subSecond()->format('Y-m-d H:i:s');
    }
}
