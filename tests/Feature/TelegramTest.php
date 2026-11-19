<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lookout\Console\TelegramNotifyCommand;
use Lookout\Contracts\SettingStore;
use Lookout\Services\TelegramChannels;
use Lookout\Services\TelegramProgress;

const TG_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

/**
 * The NightOwl tables Telegram alerts touch, on the test connection (nightowlTables() in tests/Pest.php).
 */
function telegramTables(): void
{
    nightowlTables();
}

function tgChannel(array $overrides = []): array
{
    return app(TelegramChannels::class)->create([
        'name' => 'Ops', 'bot_token' => TG_TOKEN, 'chat_id' => '-1001234567890', ...$overrides,
    ]);
}

function tgIssue(string $createdAt, array $overrides = []): int
{
    return DB::connection('testing')->table('nightowl_issues')->insertGetId([
        'type' => 'exception', 'environment' => 'production', 'status' => 'open',
        'exception_class' => 'App\\Exceptions\\PaymentFailed', 'exception_message' => 'Card <declined> & "retried"',
        'group_hash' => md5('x'.mt_rand()), 'occurrences_count' => 3, 'users_count' => 2,
        'first_seen_at' => $createdAt, 'last_seen_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt, ...$overrides,
    ]);
}

function tgReopen(int $issueId, string $at, string $actor = 'agent'): void
{
    DB::connection('testing')->table('nightowl_issue_activity')->insert([
        'issue_id' => $issueId, 'actor_type' => $actor, 'action' => 'status_changed', 'old_value' => 'resolved', 'new_value' => 'open', 'created_at' => $at,
    ]);
}

/** Each channel's watermark in the production scope, by channel id. */
function tgWatermarks(?string $scope = 'production'): array
{
    $stored = app(SettingStore::class)->get(TelegramProgress::key($scope));

    return is_array($stored) ? array_map(fn (array $e): string => $e['at'], $stored['channels']) : [];
}

/** Texts posted to Telegram, in order. */
function tgTexts(): array
{
    return Http::recorded()->map(fn (array $pair): string => $pair[0]['text'])->values()->all();
}

beforeEach(function () {
    telegramTables();
    $this->t0 = CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC');
    $this->travelTo($this->t0);
    // Every stub runs and the first answer wins, so a test changes the reply here instead of faking again.
    $this->tgReply = null;
    Http::fake(fn (Request $r) => $this->tgReply !== null ? ($this->tgReply)($r) : Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
});

it('stores Telegram channels in the setting store, not in the agent table, and never returns the token', function () {
    $this->actingAs($this->admin());

    $created = $this->postJson('/lookout/api/settings/telegram', ['name' => 'Ops', 'bot_token' => TG_TOKEN, 'chat_id' => '@ops_alerts', 'message_thread_id' => 7, 'notify_events' => ['issue.new']])
        ->assertCreated()->assertJsonPath('channel.type', 'telegram')->assertJsonPath('channel.token_preview', '…Dsaw')
        ->assertJsonPath('channel.chat_id', '@ops_alerts')->assertJsonPath('channel.message_thread_id', 7);
    expect($created->getContent())->not->toContain(TG_TOKEN)
        ->and(DB::connection('testing')->table('nightowl_alert_channels')->count())->toBe(0);

    $stored = fn (): array => app(SettingStore::class)->get(TelegramChannels::KEY)[0];
    expect($stored()['bot_token'])->toBe(TG_TOKEN);

    $id = $created->json('channel.id');
    $this->patchJson("/lookout/api/settings/telegram/{$id}", ['name' => 'Renamed', 'bot_token' => null, 'chat_id' => '-100987654321'])->assertOk()
        ->assertJsonPath('channel.name', 'Renamed')->assertJsonPath('channel.chat_id', '-100987654321');
    expect($stored()['bot_token'])->toBe(TG_TOKEN);

    $other = '987654321:'.str_repeat('B', 35);
    $this->patchJson("/lookout/api/settings/telegram/{$id}", ['bot_token' => $other, 'enabled' => false])->assertOk()
        ->assertJsonPath('channel.enabled', false)->assertJsonPath('channel.token_preview', '…BBBB');
    expect($stored()['bot_token'])->toBe($other);

    $this->deleteJson("/lookout/api/settings/telegram/{$id}")->assertOk();
    $this->deleteJson("/lookout/api/settings/telegram/{$id}")->assertNotFound();
    expect(app(TelegramChannels::class)->all())->toBe([]);
});

it('validates the bot token and the chat id', function (array $fields, ?string $error) {
    $this->actingAs($this->admin());

    $response = $this->postJson('/lookout/api/settings/telegram', ['name' => 'Ops', 'bot_token' => TG_TOKEN, 'chat_id' => '-1001234567890', ...$fields]);

    $error === null ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'group id' => [['chat_id' => '-1001234567890'], null],
    'user id' => [['chat_id' => '123456'], null],
    'channel username' => [['chat_id' => '@my_channel'], null],
    'short username' => [['chat_id' => '@abc'], 'chat_id'],
    'username with a dash' => [['chat_id' => '@my-channel'], 'chat_id'],
    'chat id with text' => [['chat_id' => '-100abc'], 'chat_id'],
    'missing chat' => [['chat_id' => ''], 'chat_id'],
    'token without bot id' => [['bot_token' => 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw'], 'bot_token'],
    'token too short' => [['bot_token' => '123:abc'], 'bot_token'],
    'token with a slash' => [['bot_token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALD/aw'], 'bot_token'],
    'missing token' => [['bot_token' => ''], 'bot_token'],
    'topic id zero' => [['message_thread_id' => 0], 'message_thread_id'],
    'unknown event' => [['notify_events' => ['issue.closed']], 'notify_events.0'],
]);

it('keeps telegram out of the agent channel types', function () {
    $this->actingAs($this->admin());

    $this->postJson('/lookout/api/settings/channels', ['name' => 't', 'type' => 'telegram', 'config' => ['webhook_url' => 'https://example.com/x']])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
});

it('lists Telegram channels together with the agent channels', function () {
    $this->actingAs($this->admin());
    $this->postJson('/lookout/api/settings/channels', ['name' => 'Slack', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/services/a']])->assertCreated();
    tgChannel();

    $settings = $this->getJson('/lookout/api/settings')->assertOk();

    expect(array_column($settings->json('alert_channels'), 'type'))->toBe(['slack', 'telegram'])
        ->and($settings->json('channel_types'))->toBe(['webhook', 'slack', 'discord', 'telegram'])
        ->and($settings->getContent())->not->toContain(TG_TOKEN);
});

it('sends a test message now and reports the Telegram error', function () {
    $this->actingAs($this->admin());
    $id = tgChannel(['message_thread_id' => 42, 'enabled' => false])['id'];

    $this->postJson("/lookout/api/settings/telegram/{$id}/test")->assertOk()->assertJsonPath('sent', true);
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.telegram.org/bot'.TG_TOKEN.'/sendMessage'
        && $r['chat_id'] === '-1001234567890' && $r['message_thread_id'] === 42 && $r['parse_mode'] === 'HTML'
        && $r['disable_web_page_preview'] === true && str_contains($r['text'], 'Test message'));

    $this->tgReply = fn () => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400);
    $this->postJson("/lookout/api/settings/telegram/{$id}/test")->assertUnprocessable()
        ->assertJsonPath('message', 'Telegram: Bad Request: chat not found');

    $this->postJson('/lookout/api/settings/telegram/999/test')->assertNotFound();
});

it('uses the configured API base', function () {
    $this->actingAs($this->admin());
    config(['lookout.telegram.api_base' => 'https://tg-proxy.example/']);
    $id = tgChannel()['id'];

    $this->postJson("/lookout/api/settings/telegram/{$id}/test")->assertOk();

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://tg-proxy.example/bot'));
});

it('starts at the first run without sending history, then sends new and reopened issues once', function () {
    tgChannel();
    tgIssue('2026-11-19 09:00:00');

    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    Http::assertNothingSent();
    expect(tgWatermarks())->toBe([1 => '2026-11-19 10:00:00']);

    $this->travelTo($this->t0->addSeconds(60));
    $new = tgIssue('2026-11-19 10:00:30');
    $old = tgIssue('2026-11-19 08:00:00', ['status' => 'open']);
    tgReopen($old, '2026-11-19 10:00:40');
    tgReopen($old, '2026-11-19 10:00:45', 'user'); // a person reopening it is not an alert

    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    $texts = tgTexts();
    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain("<b>New issue #{$new}</b>")->toContain('production')
        ->toContain('Exception: <code>App\Exceptions\PaymentFailed</code>')
        ->toContain('Card &lt;declined&gt; &amp; &quot;retried&quot;')
        ->toContain('Occurrences: 3 · Users affected: 2')
        ->toContain("<a href=\"http://localhost/lookout/issues/{$new}\">Open in Lookout</a>")
        ->and($texts[1])->toContain("<b>Reopened #{$old}</b>");

    // The next run reads the overlap again but sends nothing twice.
    $this->travelTo($this->t0->addSeconds(120));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect(tgTexts())->toHaveCount(2);
});

it('describes performance issues by their target and threshold', function () {
    tgChannel();
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:59:00');
    tgIssue('2026-11-19 09:59:30', ['type' => 'performance', 'subtype' => 'route', 'exception_class' => 'GET|HEAD /api/feed',
        'exception_message' => 'Duration exceeded threshold', 'threshold_ms' => 1000, 'triggered_duration_ms' => 2300]);

    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    expect(tgTexts()[0])->toContain('Performance · route: <code>GET|HEAD /api/feed</code>')->toContain('Exceeded 1000 ms (took 2300 ms)');
});

it('respects each channel\'s events, the enabled flag and the environment', function () {
    tgChannel(['name' => 'Reopens', 'chat_id' => '@reopens_only', 'notify_events' => ['issue.reopened']]);
    tgChannel(['name' => 'Off', 'chat_id' => '@switched_off', 'enabled' => false]);
    tgChannel(['name' => 'All', 'chat_id' => '@everything']);
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:59:00');
    $new = tgIssue('2026-11-19 09:59:10');
    $reopened = tgIssue('2026-11-19 07:00:00');
    tgReopen($reopened, '2026-11-19 09:59:20');
    tgIssue('2026-11-19 09:59:30', ['environment' => 'staging']);

    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    $sent = Http::recorded()->map(fn (array $p): array => [$p[0]['chat_id'], str_contains($p[0]['text'], 'Reopened') ? 'reopened' : 'new'])->all();
    expect($sent)->toEqualCanonicalizing([['@reopens_only', 'reopened'], ['@everything', 'new'], ['@everything', 'reopened']]);

    // '*' sends every environment.
    config(['lookout.telegram.environment' => '*']);
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.*', '2026-11-19 09:59:00');
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect(collect(tgTexts())->filter(fn (string $t): bool => str_contains($t, 'staging')))->toHaveCount(1);
});

it('caps the messages per channel in one run and sums up the rest', function () {
    tgChannel();
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:58:00');
    foreach (range(1, 23) as $i) {
        tgIssue(sprintf('2026-11-19 09:59:%02d', $i));
    }

    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    $texts = tgTexts();
    expect($texts)->toHaveCount(21)
        ->and($texts[20])->toStartWith('…and 3 more new or reopened issues')->toContain('http://localhost/lookout/issues');

    $this->travelTo($this->t0->addSeconds(60));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect(tgTexts())->toHaveCount(21);
});

it('logs a failed send without the bot token and goes on with the other channels', function () {
    Log::spy();
    tgChannel(['chat_id' => '@broken_chat']);
    tgChannel(['name' => 'Good', 'chat_id' => '@working_chat']);
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:59:00');
    tgIssue('2026-11-19 09:59:30');
    $this->tgReply = function (Request $request) {
        if ($request['chat_id'] === '@broken_chat') {
            throw new ConnectionException('cURL error 28: Operation timed out for '.$request->url());
        }

        return Http::response(['ok' => true]);
    };

    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    Http::assertSent(fn (Request $r): bool => $r['chat_id'] === '@working_chat');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Operation timed out')
        && str_contains($message, '[bot token]') && ! str_contains($message, TG_TOKEN) && ! str_contains($message, 'AAHdqTcv'));
});

it('schedules the command every minute on one server without overlapping, unless turned off', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'lookout:telegram-notify'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('does nothing while Telegram alerts are off', function () {
    config(['lookout.telegram.enabled' => false]);
    tgChannel();

    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    expect(app(SettingStore::class)->get(TelegramProgress::key('production')))->toBeNull();
    Http::assertNothingSent();
});

it('keeps each channel\'s events through an outage longer than the overlap and sends each once, in order', function () {
    tgChannel();
    $this->artisan('lookout:telegram-notify')->assertSuccessful(); // starts at 10:00:00

    /** @var list<string> $delivered */
    $delivered = [];
    $down = true;
    $failures = 0;
    $this->tgReply = function (Request $r) use (&$delivered, &$down, &$failures) {
        if ($down) {
            return ++$failures % 2 === 1 ? Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)
                : throw new ConnectionException('cURL error 7: Failed to connect');
        }
        $delivered[] = $r['text'];

        return Http::response(['ok' => true, 'result' => ['message_id' => count($delivered)]]);
    };

    $first = tgIssue('2026-11-19 10:00:30');
    $second = tgIssue('2026-11-19 10:00:30');
    $reopened = tgIssue('2026-11-19 07:00:00');
    tgReopen($reopened, '2026-11-19 10:03:00');
    $late = null;
    foreach ([60, 120, 300, 600, 1800, 3600] as $at) {
        $this->travelTo($this->t0->addSeconds($at));
        if ($at === 1800) {
            $late = tgIssue('2026-11-19 10:20:00');
        }
        $this->artisan('lookout:telegram-notify')->assertSuccessful();
        Cache::flush(); // the watermark alone has to be enough
    }
    expect($delivered)->toBe([])
        ->and(tgWatermarks()[1])->toBe('2026-11-19 10:00:29');

    $down = false;
    $this->travelTo($this->t0->addSeconds(3660));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    foreach ([3720, 3780, 7200] as $at) {
        Cache::flush();
        $this->travelTo($this->t0->addSeconds($at));
        $this->artisan('lookout:telegram-notify')->assertSuccessful();
    }

    expect($delivered)->toHaveCount(4)
        ->and($delivered[0])->toContain("New issue #{$first}</b>")
        ->and($delivered[1])->toContain("New issue #{$second}</b>")
        ->and($delivered[2])->toContain("Reopened #{$reopened}</b>")
        ->and($delivered[3])->toContain("New issue #{$late}</b>")
        ->and(tgWatermarks()[1])->toBe('2026-11-19 11:58:00');
});

it('retries from the first undelivered event when a channel fails half way through a run', function () {
    tgChannel();
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:50:00');
    $ids = [tgIssue('2026-11-19 09:51:00'), tgIssue('2026-11-19 09:52:00'), tgIssue('2026-11-19 09:52:00'), tgIssue('2026-11-19 09:53:00')];
    $delivered = [];
    $calls = 0;
    $this->tgReply = function (Request $r) use (&$delivered, &$calls) {
        if (++$calls === 3) {
            return Http::response(['ok' => false, 'description' => 'Too Many Requests: retry after 5'], 429);
        }
        $delivered[] = $r['text'];

        return Http::response(['ok' => true]);
    };

    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect($delivered)->toHaveCount(2)->and(tgWatermarks()[1])->toBe('2026-11-19 09:51:59');

    Cache::flush();
    $this->travelTo($this->t0->addSeconds(60));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    expect(array_map(fn (string $t): int => (int) preg_replace('/^.*?#(\d+).*$/s', '$1', $t), $delivered))->toBe($ids);
});

it('does not let a failing channel hold back the others', function () {
    tgChannel(['name' => 'Broken', 'chat_id' => '@broken_chat']);
    tgChannel(['name' => 'Good', 'chat_id' => '@working_chat']);
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    $brokenDown = true;
    $got = ['@broken_chat' => [], '@working_chat' => []];
    $this->tgReply = function (Request $r) use (&$got, &$brokenDown) {
        if ($brokenDown && $r['chat_id'] === '@broken_chat') {
            return Http::response(['ok' => false, 'description' => 'Internal Server Error'], 500);
        }
        $got[$r['chat_id']][] = (int) preg_replace('/^.*?#(\d+).*$/s', '$1', $r['text']);

        return Http::response(['ok' => true]);
    };

    $a = tgIssue('2026-11-19 10:00:30');
    $this->travelTo($this->t0->addSeconds(60));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect($got['@working_chat'])->toBe([$a]);

    $b = tgIssue('2026-11-19 10:09:00');
    $this->travelTo($this->t0->addSeconds(600));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect($got['@working_chat'])->toBe([$a, $b])->and($got['@broken_chat'])->toBe([])
        ->and(tgWatermarks())->toBe([1 => '2026-11-19 10:00:29', 2 => '2026-11-19 10:08:00']);

    $brokenDown = false;
    $this->travelTo($this->t0->addSeconds(660));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect($got['@broken_chat'])->toBe([$a, $b])->and($got['@working_chat'])->toBe([$a, $b]);
});

it('takes over the shared watermark of earlier versions once, without history or gaps', function () {
    tgChannel(['chat_id' => '@first']);
    tgChannel(['chat_id' => '@second']);
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:58:00');
    tgIssue('2026-11-19 09:57:00'); // handled by the old version
    $missed = tgIssue('2026-11-19 09:59:00');

    $this->artisan('lookout:telegram-notify')->assertSuccessful()->expectsOutputToContain('Took over the shared watermark');

    $sent = Http::recorded()->map(fn (array $p): array => [$p[0]['chat_id'], (int) preg_replace('/^.*?#(\d+).*$/s', '$1', $p[0]['text'])])->all();
    expect($sent)->toBe([['@first', $missed], ['@second', $missed]])
        ->and(tgWatermarks())->toBe([1 => '2026-11-19 09:58:00', 2 => '2026-11-19 09:58:00']);

    // The old key is read only once: moving it later changes nothing.
    app(SettingStore::class)->put(TelegramNotifyCommand::WATERMARK_KEY.'.production', '2026-11-19 09:00:00');
    $this->travelTo($this->t0->addSeconds(60));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    expect(Http::recorded())->toHaveCount(2);
});

it('starts a new channel at now and drops the watermark of a deleted one', function () {
    $this->actingAs($this->admin());
    tgChannel(['chat_id' => '@old_channel']);
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    $before = tgIssue('2026-11-19 10:00:30');

    $this->travelTo($this->t0->addSeconds(60));
    $id = $this->postJson('/lookout/api/settings/telegram', ['name' => 'New', 'bot_token' => TG_TOKEN, 'chat_id' => '@new_channel'])->assertCreated()->json('channel.id');
    expect(tgWatermarks()[$id])->toBe('2026-11-19 10:01:00');
    $after = tgIssue('2026-11-19 10:01:30');

    $this->travelTo($this->t0->addSeconds(120));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    $sent = Http::recorded()->map(fn (array $p): array => [$p[0]['chat_id'], (int) preg_replace('/^.*?#(\d+).*$/s', '$1', $p[0]['text'])])->all();
    expect($sent)->toBe([['@old_channel', $before], ['@old_channel', $after], ['@new_channel', $after]]);

    $this->deleteJson("/lookout/api/settings/telegram/{$id}")->assertOk();
    expect(array_keys(tgWatermarks()))->toBe([1]);
});

it('sends a disabled channel nothing from while it was off', function () {
    $this->actingAs($this->admin());
    $id = tgChannel(['enabled' => false])['id'];
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    tgIssue('2026-11-19 10:00:30');

    $this->travelTo($this->t0->addSeconds(60));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();
    $this->patchJson("/lookout/api/settings/telegram/{$id}", ['enabled' => true])->assertOk();
    $this->travelTo($this->t0->addSeconds(120));
    $this->artisan('lookout:telegram-notify')->assertSuccessful();

    Http::assertNothingSent();
});
