<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Lookout\Mcp\LookoutServer;
use Lookout\Models\McpToken;
use Lookout\Services\Settings;
use Lookout\Services\TelegramChannels;

const MCP_BOT_TOKEN = '987654321:AAHsecretSecretSecretSecretSecret00';
const MCP_HOOK_URL = 'https://hooks.example.com/lookout/very-secret-path';
const MCP_HOOK_SECRET = 'whsec-signing-secret-value';
const MCP_SLACK_URL = 'https://hooks.slack.com/services/T000/B000/slacksecretpath';

function mcpRpc(string $token, string $method, array $params = []): TestResponse
{
    return test()->postJson('/mcp/lookout', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], ['Authorization' => "Bearer {$token}"]);
}

function mcpCall(string $token, string $tool, array $arguments = []): TestResponse
{
    $response = mcpRpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk();
    $GLOBALS['mcpResponses'][] = $response->getContent();

    return $response;
}

/** Tool names tools/list shows to this credential. */
function mcpToolNames(string $token): array
{
    return collect(mcpRpc($token, 'tools/list', ['per_page' => 50])->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();
}

function mcpIssue(array $overrides = []): int
{
    $now = now('UTC')->format('Y-m-d H:i:s');

    return DB::connection('testing')->table('nightowl_issues')->insertGetId([
        'type' => 'exception', 'environment' => 'production', 'status' => 'open', 'exception_class' => 'RuntimeException',
        'group_hash' => md5((string) mt_rand()), 'created_at' => $now, 'updated_at' => $now, ...$overrides,
    ]);
}

function mcpActivity(int $issueId): array
{
    return DB::connection('testing')->table('nightowl_issue_activity')->where('issue_id', $issueId)->orderBy('id')->get()
        ->map(fn (object $a): array => [$a->action, $a->old_value, $a->new_value, $a->actor_type, json_decode($a->actor_meta, true)['via'] ?? null, $a->user_id])->all();
}

beforeEach(function () {
    nightowlTables();
    $GLOBALS['mcpResponses'] = [];
    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = [$e->level, $e->message, $e->context]);
    $this->writer = $this->admin();
    $this->writeToken = McpToken::issue($this->writer, 'writer', true)['plain_token'];
    $this->readToken = McpToken::issue($this->admin(), 'reader')['plain_token'];
});

it('hides the write tools from a read-only personal token and refuses them with a clear error', function () {
    $issue = mcpIssue();

    expect(mcpToolNames($this->readToken))->toBe(['failed_jobs', 'get_issue', 'get_settings', 'list_issues', 'slow_queries', 'slow_routes'])
        ->and(mcpToolNames($this->writeToken))->toBe(['comment_issue', 'create_alert_channel', 'delete_alert_channel', 'delete_threshold', 'failed_jobs',
            'get_issue', 'get_settings', 'list_issues', 'resolve_issue', 'set_auto_resolve', 'set_threshold', 'slow_queries', 'slow_routes',
            'update_alert_channel', 'update_issue']);

    foreach (LookoutServer::writeToolNames() as $tool) {
        mcpRpc($this->readToken, 'tools/call', ['name' => $tool, 'arguments' => (object) ['id' => $issue]])
            ->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, "Tool [{$tool}] changes monitoring and needs write access") && str_contains($m, 'Write access'));
    }
    expect(DB::connection('testing')->table('nightowl_issues')->value('status'))->toBe('open')
        ->and(mcpActivity($issue))->toBe([]);
});

it('keeps the shared token read-only unless LOOKOUT_MCP_TOKEN_CAN_WRITE is on', function () {
    $shared = str_repeat('s', 40);
    config(['lookout.mcp.token' => $shared, 'lookout.mcp.user_id' => $this->writer->id]);
    $issue = mcpIssue();

    expect(config('lookout.mcp.token_can_write'))->toBeFalse()
        ->and(mcpToolNames($shared))->not->toContain('resolve_issue');
    mcpRpc($shared, 'tools/call', ['name' => 'resolve_issue', 'arguments' => (object) ['id' => $issue]])
        ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, 'LOOKOUT_MCP_TOKEN_CAN_WRITE=true'));

    config(['lookout.mcp.token_can_write' => true]);
    expect(mcpToolNames($shared))->toContain('resolve_issue', 'update_alert_channel');
    mcpCall($shared, 'resolve_issue', ['id' => $issue])->assertJsonPath('result.structuredContent.status', 'resolved');
});

it('changes issues through resolve_issue, update_issue and comment_issue, each recorded in the activity', function () {
    $issue = mcpIssue();
    $assignee = $this->admin();
    $uid = (string) $this->writer->id;

    mcpCall($this->writeToken, 'resolve_issue', ['id' => $issue])->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.changed', true);
    mcpCall($this->writeToken, 'update_issue', ['id' => $issue, 'status' => 'ignored', 'priority' => 'high', 'assigned_to' => $assignee->id])
        ->assertJsonPath('result.structuredContent.status', 'ignored')->assertJsonPath('result.structuredContent.priority', 'high')
        ->assertJsonPath('result.structuredContent.assigned_to', (string) $assignee->id)
        ->assertJsonPath('result.structuredContent.changed', ['status', 'priority', 'assigned_to']);
    mcpCall($this->writeToken, 'update_issue', ['id' => $issue, 'priority' => null, 'assigned_to' => null])
        ->assertJsonPath('result.structuredContent.priority', null)->assertJsonPath('result.structuredContent.assigned_to', null);
    mcpCall($this->writeToken, 'comment_issue', ['id' => $issue, 'body' => 'Fixed in **#42**.'])
        ->assertJsonPath('result.structuredContent.comment.body', 'Fixed in **#42**.')->assertJsonPath('result.structuredContent.comment.actor_type', 'mcp');

    expect(mcpActivity($issue))->toBe([
        ['status_changed', 'open', 'resolved', 'user', 'mcp', $uid],
        ['status_changed', 'resolved', 'ignored', 'user', 'mcp', $uid],
        ['priority_changed', null, 'high', 'user', 'mcp', $uid],
        ['assigned', null, (string) $assignee->id, 'user', 'mcp', $uid],
        ['priority_changed', 'high', null, 'user', 'mcp', $uid],
        ['assigned', (string) $assignee->id, null, 'user', 'mcp', $uid],
        ['commented', null, 'Fixed in **#42**.', 'user', 'mcp', $uid],
    ])->and(DB::connection('testing')->table('nightowl_issue_comments')->value('user_id'))->toBe($uid);
});

it('turns issue validation errors into MCP errors', function () {
    $issue = mcpIssue();
    $outsider = $this->admin(false);

    mcpCall($this->writeToken, 'update_issue', ['id' => $issue, 'status' => 'closed'])->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', fn (string $t): bool => str_contains($t, 'status'));
    mcpCall($this->writeToken, 'update_issue', ['id' => $issue, 'assigned_to' => $outsider->id])->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', fn (string $t): bool => str_contains($t, 'Issues can be assigned to admins only.'));
    mcpCall($this->writeToken, 'update_issue', ['id' => $issue])->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Nothing to change: give status, priority or assigned_to.');
    mcpCall($this->writeToken, 'update_issue', ['id' => 999999, 'status' => 'resolved'])->assertJsonPath('result.content.0.text', 'No issue with this id.');
    mcpCall($this->writeToken, 'comment_issue', ['id' => $issue, 'body' => ''])->assertJsonPath('result.isError', true);

    expect(mcpActivity($issue))->toBe([]);
});

it('reads the settings with any token and changes thresholds and auto-resolve with a write token', function () {
    mcpCall($this->readToken, 'get_settings')->assertJsonPath('result.structuredContent.thresholds', [])
        ->assertJsonPath('result.structuredContent.auto_resolve_days', null);

    mcpCall($this->writeToken, 'set_threshold', ['type' => 'route', 'target' => 'get /api/orders', 'duration_ms' => 800])
        ->assertJsonPath('result.structuredContent.replaced', false)
        ->assertJsonPath('result.structuredContent.threshold', ['type' => 'route', 'target' => 'GET|HEAD /api/orders', 'duration_ms' => 800]);
    mcpCall($this->writeToken, 'set_threshold', ['type' => 'route', 'target' => 'GET|HEAD /api/orders', 'duration_ms' => 1200])
        ->assertJsonPath('result.structuredContent.replaced', true);
    mcpCall($this->writeToken, 'set_threshold', ['type' => 'job', 'duration_ms' => 5000]);
    expect(app(Settings::class)->thresholds())->toBe([
        ['type' => 'route', 'target' => 'GET|HEAD /api/orders', 'duration_ms' => 1200],
        ['type' => 'job', 'target' => null, 'duration_ms' => 5000],
    ]);

    mcpCall($this->writeToken, 'set_threshold', ['type' => 'nonsense', 'duration_ms' => 0])->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', fn (string $t): bool => str_contains($t, 'type') && str_contains($t, 'duration'));
    mcpCall($this->writeToken, 'delete_threshold', ['type' => 'route', 'target' => 'GET /api/orders'])->assertJsonPath('result.structuredContent.deleted', true);
    mcpCall($this->writeToken, 'delete_threshold', ['type' => 'route', 'target' => 'GET /api/orders'])->assertJsonPath('result.content.0.text', 'No threshold for this type and target.');
    expect(app(Settings::class)->thresholds())->toBe([['type' => 'job', 'target' => null, 'duration_ms' => 5000]]);

    mcpCall($this->writeToken, 'set_auto_resolve', ['days' => 14])->assertJsonPath('result.structuredContent.auto_resolve_days', 14);
    mcpCall($this->writeToken, 'set_auto_resolve', ['days' => 5])->assertJsonPath('result.isError', true);
    mcpCall($this->writeToken, 'set_auto_resolve', ['days' => null])->assertJsonPath('result.structuredContent.auto_resolve_days', null);

    $changes = collect($this->logged)->filter(fn (array $l): bool => str_starts_with($l[1], 'Lookout MCP:'));
    expect($changes->pluck(1)->all())->toBe(['Lookout MCP: threshold added', 'Lookout MCP: threshold changed', 'Lookout MCP: threshold added',
        'Lookout MCP: threshold deleted', 'Lookout MCP: auto-resolve changed', 'Lookout MCP: auto-resolve changed'])
        ->and($changes->first()[2])->toMatchArray(['user_id' => $this->writer->id, 'user_name' => $this->writer->name, 'via' => 'mcp', 'target' => 'GET|HEAD /api/orders'])
        ->and($changes->last()[2])->toMatchArray(['old_days' => 14, 'days' => null]);
});

it('manages webhook, Slack, Discord and Telegram channels with the Settings rules and never returns or logs a secret', function () {
    // The same URL rules as the Settings page.
    mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'slack', 'name' => 'Slack', 'url' => 'https://example.com/hook'])
        ->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', fn (string $t): bool => str_contains($t, 'https://hooks.slack.com/'));
    mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'webhook', 'name' => 'Local', 'url' => 'https://127.0.0.1/x'])
        ->assertJsonPath('result.content.0.text', fn (string $t): bool => str_contains($t, 'private, loopback or link-local'));
    mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'telegram', 'name' => 'TG', 'bot_token' => 'nope', 'chat_id' => '@ab'])
        ->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', fn (string $t): bool => str_contains($t, 'bot token') && str_contains($t, 'chat id'));
    mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'slack', 'name' => 'Slack', 'url' => MCP_SLACK_URL, 'bot_token' => MCP_BOT_TOKEN])
        ->assertJsonPath('result.content.0.text', 'A slack channel does not take bot_token.');
    mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'sms', 'name' => 'x'])->assertJsonPath('result.isError', true);

    $hook = mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'webhook', 'name' => 'Hook', 'url' => MCP_HOOK_URL, 'secret' => MCP_HOOK_SECRET, 'events' => ['issue.new']])
        ->assertJsonPath('result.structuredContent.channel.url_preview', 'https://hooks.example.com/…')
        ->assertJsonPath('result.structuredContent.channel.has_secret', true)->json('result.structuredContent.channel.id');
    $slack = mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'slack', 'name' => 'Slack', 'url' => MCP_SLACK_URL])->json('result.structuredContent.channel.id');
    mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'discord', 'name' => 'Discord', 'url' => 'https://discord.com/api/webhooks/1/discordsecret', 'enabled' => false])
        ->assertJsonPath('result.structuredContent.channel.enabled', false);
    $tg = mcpCall($this->writeToken, 'create_alert_channel', ['type' => 'telegram', 'name' => 'TG', 'bot_token' => MCP_BOT_TOKEN, 'chat_id' => '-1001234567890', 'message_thread_id' => 7])
        ->assertJsonPath('result.structuredContent.channel.token_preview', '…et00')->json('result.structuredContent.channel.id');
    expect(app(TelegramChannels::class)->find($tg)['bot_token'])->toBe(MCP_BOT_TOKEN);

    // Telegram ids are their own: id 1 exists in both stores, the type picks one.
    mcpCall($this->writeToken, 'update_alert_channel', ['id' => $tg, 'type' => 'telegram', 'name' => 'Ops TG', 'chat_id' => '@ops_alerts'])
        ->assertJsonPath('result.structuredContent.channel.name', 'Ops TG')->assertJsonPath('result.structuredContent.channel.chat_id', '@ops_alerts');
    expect(app(TelegramChannels::class)->find($tg)['bot_token'])->toBe(MCP_BOT_TOKEN);
    mcpCall($this->writeToken, 'update_alert_channel', ['id' => $hook, 'type' => 'webhook', 'url' => 'https://other.example.com/x'])
        ->assertJsonPath('result.structuredContent.channel.has_secret', false); // a new URL drops the old secret, as on the Settings page
    mcpCall($this->writeToken, 'update_alert_channel', ['id' => $slack, 'type' => 'discord', 'name' => 'x'])->assertJsonPath('result.content.0.text', 'No discord channel with this id.');
    mcpCall($this->writeToken, 'update_alert_channel', ['id' => $slack, 'type' => 'slack', 'url' => 'https://evil.example/x'])->assertJsonPath('result.isError', true);

    $settings = mcpCall($this->readToken, 'get_settings');
    expect(collect($settings->json('result.structuredContent.alert_channels'))->map(fn (array $c): string => $c['type'].':'.$c['name'])->all())
        ->toBe(['webhook:Hook', 'slack:Slack', 'discord:Discord', 'telegram:Ops TG']);

    mcpCall($this->writeToken, 'delete_alert_channel', ['id' => $tg, 'type' => 'telegram'])->assertJsonPath('result.structuredContent.deleted', true);
    mcpCall($this->writeToken, 'delete_alert_channel', ['id' => $slack, 'type' => 'webhook'])->assertJsonPath('result.content.0.text', 'No webhook channel with this id.');
    mcpCall($this->writeToken, 'delete_alert_channel', ['id' => $slack, 'type' => 'slack'])->assertJsonPath('result.structuredContent.deleted', true);
    expect(app(TelegramChannels::class)->all())->toBe([])
        ->and(DB::connection('testing')->table('nightowl_alert_channels')->pluck('name')->all())->toBe(['Hook', 'Discord']);

    $logged = json_encode($this->logged);
    $answered = implode("\n", $GLOBALS['mcpResponses']);
    foreach ([MCP_BOT_TOKEN, 'AAHsecret', MCP_HOOK_URL, 'very-secret-path', MCP_HOOK_SECRET, MCP_SLACK_URL, 'slacksecretpath', 'discordsecret'] as $secret) {
        expect($answered)->not->toContain($secret)->and($logged)->not->toContain($secret);
    }
    $created = collect($this->logged)->first(fn (array $l): bool => $l[1] === 'Lookout MCP: alert channel created');
    expect($created[2])->toMatchArray(['user_id' => $this->writer->id, 'type' => 'webhook', 'name' => 'Hook', 'fields' => ['name', 'events', 'url', 'secret']]);
});

it('takes write access as can_write or, as before 0.3.0, can_resolve, and shows the scope', function () {
    $this->actingAs($this->admin());

    $old = $this->postJson('/lookout/api/mcp/tokens', ['name' => 'Old client', 'can_resolve' => true])->assertCreated()
        ->assertJsonPath('token.scope', 'write')->assertJsonPath('token.can_write', true)->assertJsonPath('token.can_resolve', true);
    $new = $this->postJson('/lookout/api/mcp/tokens', ['name' => 'New client', 'can_write' => true])->assertCreated()->assertJsonPath('token.scope', 'write');
    $this->postJson('/lookout/api/mcp/tokens', ['name' => 'Default'])->assertCreated()->assertJsonPath('token.scope', 'read')->assertJsonPath('token.can_write', false);

    expect(mcpToolNames($old->json('plain_token')))->toContain('update_issue');

    $this->patchJson('/lookout/api/mcp/tokens/'.$old->json('token.id'), ['can_resolve' => false])->assertOk()->assertJsonPath('token.scope', 'read');
    $this->patchJson('/lookout/api/mcp/tokens/'.$new->json('token.id'), ['can_write' => false])->assertOk()->assertJsonPath('token.can_write', false);
    $this->patchJson('/lookout/api/mcp/tokens/'.$new->json('token.id'), [])->assertUnprocessable();

    expect(mcpToolNames($old->json('plain_token')))->not->toContain('update_issue');
    $this->getJson('/lookout/api/mcp/tokens')->assertOk()->assertJsonPath('tokens.0.scope', 'read');
});
