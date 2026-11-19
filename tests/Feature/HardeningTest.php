<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Lookout\Lookout;
use Lookout\Models\McpToken;

function mcpList(array $headers = [], string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/mcp/lookout', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers);
}

/**
 * nightowl_alert_channels on the test connection, as the agent's migration creates it (the columns Lookout writes).
 */
function alertChannelsTable(): void
{
    config(['lookout.connection' => 'testing']);
    Schema::connection('testing')->create('nightowl_alert_channels', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('type');
        $table->text('config');
        $table->boolean('enabled')->default(true);
        $table->timestamps();
    });
}

it('closes the MCP server (404) while Lookout is disabled, even for a valid token', function () {
    $plain = McpToken::issue($this->admin(), 'a')['plain_token'];
    mcpList(['Authorization' => "Bearer {$plain}"])->assertOk();

    config(['lookout.enabled' => false]);
    mcpList(['Authorization' => "Bearer {$plain}"])->assertNotFound();

    config(['lookout.enabled' => null, 'nightowl.enabled' => false]);
    mcpList(['Authorization' => "Bearer {$plain}"])->assertNotFound();
});

it('answers 429 with Retry-After after too many failed MCP attempts from one IP, counting only failures', function () {
    config(['lookout.mcp.failed_per_minute' => 3]);
    $plain = McpToken::issue($this->admin(), 'a')['plain_token'];

    foreach (range(1, 5) as $_) {
        mcpList(['Authorization' => "Bearer {$plain}"])->assertOk();
    }
    foreach (range(1, 3) as $_) {
        mcpList(['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    mcpList(['Authorization' => 'Bearer wrong'])->assertTooManyRequests()->assertHeader('Retry-After');
    mcpList(['Authorization' => "Bearer {$plain}"])->assertTooManyRequests();
    mcpList(['Authorization' => "Bearer {$plain}"], '10.0.0.2')->assertOk();
});

it('warns once when the shared MCP token is shorter than 32 characters', function () {
    Log::spy();
    config(['lookout.mcp.token' => 'short-secret', 'lookout.mcp.user_id' => $this->admin()->id]);

    mcpList(['X-Api-Token' => 'short-secret'])->assertOk();
    mcpList(['X-Api-Token' => 'short-secret'])->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'LOOKOUT_MCP_TOKEN'));
});

it('does not warn about a long shared MCP token', function () {
    Log::spy();
    config(['lookout.mcp.token' => str_repeat('a', 32), 'lookout.mcp.user_id' => $this->admin()->id]);

    mcpList(['X-Api-Token' => str_repeat('a', 32)])->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('keeps Slack and Discord channels on their own webhook hosts and generic webhooks off internal addresses', function (string $type, string $url, bool $ok) {
    alertChannelsTable();
    $this->actingAs($this->admin());
    $key = $type === 'webhook' ? 'url' : 'webhook_url';

    $response = $this->postJson('/lookout/api/settings/channels', ['name' => 'c', 'type' => $type, 'config' => [$key => $url]]);

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors("config.{$key}");
})->with([
    ['slack', 'https://hooks.slack.com/services/T0/B0/x', true],
    ['slack', 'https://attacker.example/hooks.slack.com/', false],
    ['slack', 'https://hooks.slack.com.attacker.example/x', false],
    ['discord', 'https://discord.com/api/webhooks/1/x', true],
    ['discord', 'https://discordapp.com/api/webhooks/1/x', true],
    ['discord', 'https://discord.com/other', false],
    ['webhook', 'https://example.com/hook', true],
    ['webhook', 'https://8.8.8.8/hook', true],
    ['webhook', 'https://localhost/hook', false],
    ['webhook', 'https://api.localhost/hook', false],
    ['webhook', 'https://127.0.0.1/hook', false],
    ['webhook', 'https://10.1.2.3/hook', false],
    ['webhook', 'https://192.168.0.203:8000/hook', false],
    ['webhook', 'https://169.254.169.254/latest', false],
    ['webhook', 'https://[::1]/hook', false],
    ['webhook', 'https://[fe80::1]/hook', false],
    ['webhook', 'https://2130706433/hook', false],
]);

it('validates a changed channel URL on update too', function () {
    alertChannelsTable();
    $this->actingAs($this->admin());
    $id = $this->postJson('/lookout/api/settings/channels', ['name' => 's', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/services/a']])->json('channel.id');

    $this->patchJson("/lookout/api/settings/channels/{$id}", ['config' => ['webhook_url' => 'https://attacker.example/x']])
        ->assertUnprocessable()->assertJsonValidationErrors('config.webhook_url');
});

it('drops the stored webhook secret when the URL changes, unless a new one is sent', function () {
    alertChannelsTable();
    $this->actingAs($this->admin());
    $id = $this->postJson('/lookout/api/settings/channels', ['name' => 'w', 'type' => 'webhook', 'config' => ['url' => 'https://a.example/hook', 'secret' => 'old-secret']])
        ->assertCreated()->assertJsonPath('channel.has_secret', true)->json('channel.id');
    $stored = fn (): array => json_decode(DB::connection('testing')->table('nightowl_alert_channels')->where('id', $id)->value('config'), true);

    $this->patchJson("/lookout/api/settings/channels/{$id}", ['name' => 'renamed', 'config' => ['url' => 'https://a.example/hook']])
        ->assertOk()->assertJsonPath('channel.has_secret', true);

    $this->patchJson("/lookout/api/settings/channels/{$id}", ['config' => ['url' => 'https://b.example/hook']])
        ->assertOk()->assertJsonPath('channel.has_secret', false);
    expect($stored())->toBe(['url' => 'https://b.example/hook']);

    $this->patchJson("/lookout/api/settings/channels/{$id}", ['config' => ['url' => 'https://c.example/hook', 'secret' => 'new-secret']])
        ->assertOk()->assertJsonPath('channel.has_secret', true);
    expect($stored()['secret'])->toBe('new-secret');
});

it('checks only the first users by name against the gate for the assignee list', function () {
    $now = now();
    foreach (array_chunk(range(1, Lookout::ASSIGNABLE_SCAN_LIMIT), 500) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $i): array => ['name' => sprintf('A %05d', $i), 'email' => "a{$i}@example.test", 'is_admin' => false, 'created_at' => $now, 'updated_at' => $now], $chunk));
    }
    $late = $this->admin();
    $late->forceFill(['name' => 'Zed'])->save();
    $this->actingAs($late);

    expect($this->getJson('/lookout/api/issues/assignees')->assertOk()->json('assignees'))->toBe([]);
});

it('caps how deep the logs can be paged', function () {
    $this->actingAs($this->admin());

    $this->getJson('/lookout/api/logs?page=102&limit=100')->assertUnprocessable()->assertJsonValidationErrors('page');
    $this->getJson('/lookout/api/logs?page=202&limit=50')->assertUnprocessable()->assertJsonValidationErrors('page');
});

it('forbids framing the Lookout shell', function () {
    $this->actingAs($this->admin());

    $this->get('/lookout/issues')->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});
