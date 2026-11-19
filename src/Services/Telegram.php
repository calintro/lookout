<?php

namespace Lookout\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core as NightwatchCore;
use Laravel\Nightwatch\Facades\Nightwatch;
use Lookout\Lookout;
use Throwable;

/**
 * Sends Lookout's Telegram alerts through the Bot API (sendMessage, HTML) and builds their text. The bot token is part
 * of the request path, so neither the URL nor an unscrubbed error is ever logged or returned, and the call runs
 * outside Nightwatch recording so the URL does not land in the outgoing-request telemetry either.
 */
class Telegram
{
    public const MESSAGE_CHARS = 300;

    /**
     * Posts one message. Returns null when Telegram accepted it, else why not (token scrubbed). No retries here.
     *
     * @param  array<string, mixed>  $channel
     */
    public function send(array $channel, string $html): ?string
    {
        $token = (string) ($channel['bot_token'] ?? '');
        $payload = array_filter([
            'chat_id' => (string) ($channel['chat_id'] ?? ''),
            'message_thread_id' => $channel['message_thread_id'] ?? null,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'link_preview_options' => ['is_disabled' => true],
        ], fn ($v) => $v !== null);
        $url = rtrim((string) config('lookout.telegram.api_base', 'https://api.telegram.org'), '/').'/bot'.$token.'/sendMessage';

        try {
            $response = self::unrecorded(fn () => Http::timeout(5)->connectTimeout(5)->acceptJson()->asJson()->post($url, $payload));
        } catch (Throwable $e) {
            return $this->failed($channel, self::scrub(class_basename($e).': '.$e->getMessage(), $token));
        }

        if ($response->successful() && $response->json('ok') === true) {
            return null;
        }
        $description = $response->json('description');

        return $this->failed($channel, self::scrub(is_string($description) && $description !== '' ? $description : 'HTTP '.$response->status(), $token));
    }

    /**
     * The alert for one issue event.
     *
     * @param  object  $issue  a nightowl_issues row
     */
    public function issueMessage(object $issue, string $event): string
    {
        $e = fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isPerf = ($issue->type ?? 'exception') === 'performance';
        $subtype = str_replace('_', ' ', (string) ($issue->subtype ?? ''));
        $name = (string) ($issue->exception_class ?? '') ?: ($isPerf ? 'Slow '.($subtype ?: 'execution') : 'Exception');
        $kind = $isPerf ? 'Performance'.($subtype !== '' ? ' · '.$subtype : '') : 'Exception';

        $message = trim((string) preg_replace('/\s+/u', ' ', (string) ($issue->exception_message ?? '')));
        if ($isPerf && isset($issue->threshold_ms, $issue->triggered_duration_ms)) {
            $message = "Exceeded {$issue->threshold_ms} ms (took {$issue->triggered_duration_ms} ms)";
        }
        if (mb_strlen($message) > self::MESSAGE_CHARS) {
            $message = rtrim(mb_substr($message, 0, self::MESSAGE_CHARS)).'…';
        }
        $location = $isPerf ? null : $this->location($issue);

        $lines = [
            '<b>'.($event === 'issue.reopened' ? 'Reopened' : 'New issue').' #'.(int) $issue->id.'</b> · '.$e(self::appName()).(($issue->environment ?? null) ? ' · '.$e($issue->environment) : ''),
            $e($kind).': <code>'.$e(mb_strimwidth($name, 0, 300, '…')).'</code>',
            $message !== '' ? $e($message) : null,
            $location !== null ? 'Location: <code>'.$e($location).'</code>' : null,
            'Occurrences: '.number_format((int) ($issue->occurrences_count ?? 0)).' · Users affected: '.number_format((int) ($issue->users_count ?? 0)),
            '<a href="'.$e(self::lookoutUrl('issues/'.(int) $issue->id)).'">Open in Lookout</a>',
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    public function summaryMessage(int $more, ?string $environment): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '…and '.$more.' more new or reopened '.($more === 1 ? 'issue' : 'issues').' · '.$e(self::appName()).($environment ? ' · '.$e($environment) : '')
            ."\n".'<a href="'.$e(self::lookoutUrl('issues')).'">Open the issues in Lookout</a>';
    }

    public function testMessage(): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $env = (string) (config('nightowl.environment') ?? config('app.env'));

        return '<b>Test message</b> · '.$e(self::appName()).($env !== '' ? ' · '.$e($env) : '')
            ."\nLookout will post new and reopened issues to this chat.";
    }

    /**
     * An absolute link to a Lookout page, built like the SPA routes it (/{path}/{rel}), on Lookout's domain if any.
     */
    public static function lookoutUrl(string $rel): string
    {
        return Route::has('lookout') ? route('lookout', ['any' => $rel]) : url(Lookout::path().'/'.$rel);
    }

    public static function appName(): string
    {
        return (string) (config('app.name') ?: 'Laravel');
    }

    /**
     * The file:line of the latest occurrence of an exception issue, best effort (PostgreSQL storage v2 only).
     */
    private function location(object $issue): ?string
    {
        $db = DB::connection(Lookout::connection());
        if ($db->getDriverName() !== 'pgsql' || ! is_string($issue->group_hash ?? null) || preg_match('/^[0-9a-f]{32}$/i', $issue->group_hash) !== 1) {
            return null;
        }

        try {
            $row = $db->table('nightowl_exceptions_v2')->whereRaw("fingerprint = decode(?, 'hex')", [strtolower($issue->group_hash)])
                ->orderByDesc('created_at')->first(['file', 'line']);
        } catch (Throwable) {
            return null;
        }

        return $row !== null && $row->file ? $row->file.($row->line ? ':'.$row->line : '') : null;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function failed(array $channel, string $error): string
    {
        Log::warning('Lookout: Telegram message to channel "'.($channel['name'] ?? '?').'" (#'.($channel['id'] ?? '?').') failed: '.$error);

        return $error;
    }

    private static function scrub(string $text, string $token): string
    {
        return $token !== '' ? str_replace([$token, rawurlencode($token)], '[bot token]', $text) : $text;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function unrecorded(callable $callback): mixed
    {
        return class_exists(Nightwatch::class) && app()->bound(NightwatchCore::class) ? Nightwatch::ignore($callback) : $callback();
    }
}
