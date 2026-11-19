<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Lookout\Mcp\LookoutServer;
use Lookout\Mcp\Tools\ListIssuesTool;
use Lookout\Models\McpToken;
use Lookout\Tests\Fixtures\User;

function packageMcpList(array $headers = []): TestResponse
{
    return test()->postJson('/mcp/lookout', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers);
}

it('stays closed without a credential and while no shared token is set', function () {
    packageMcpList()->assertUnauthorized();
    packageMcpList(['X-Api-Token' => 'anything'])->assertUnauthorized();
});

it('opens for a personal token of a user the gate lets in, with the configured prefix', function () {
    config(['lookout.mcp.token_prefix' => 'xyz_']);
    ['token' => $token, 'plain_token' => $plain] = McpToken::issue($this->admin(), 'Claude Code');

    $names = collect(packageMcpList(['Authorization' => "Bearer {$plain}"])->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['failed_jobs', 'get_issue', 'get_settings', 'list_issues', 'slow_queries', 'slow_routes'])
        ->and($plain)->toStartWith('xyz_')
        ->and($token->fresh()->prefix)->toBe(substr($plain, 0, 8))
        ->and($token->fresh()->last_used_at)->not->toBeNull();
});

it('refuses a personal token whose owner the MCP gate refuses, and a revoked one', function () {
    $owner = $this->admin();
    ['token' => $token, 'plain_token' => $plain] = McpToken::issue($owner, 'x');

    Gate::define('useLookoutMcp', fn (User $user): bool => false);
    config(['lookout.mcp.gate' => 'useLookoutMcp']);
    packageMcpList(['X-Api-Token' => $plain])->assertForbidden();

    config(['lookout.mcp.gate' => null]);
    $token->revoke();
    packageMcpList(['X-Api-Token' => $plain])->assertUnauthorized();
});

it('opens for the shared token only while its user passes the gate', function () {
    config(['lookout.mcp.token' => 'shared-secret-0123456789', 'lookout.mcp.user_id' => $this->admin(false)->id]);
    packageMcpList(['X-Api-Token' => 'shared-secret-0123456789'])->assertForbidden();

    config(['lookout.mcp.user_id' => $this->admin()->id]);
    packageMcpList(['X-Api-Token' => 'shared-secret-0123456789'])->assertOk();
    packageMcpList(['X-Api-Token' => 'wrong'])->assertUnauthorized();
});

it('throttles per credential', function () {
    config(['lookout.mcp.per_minute' => 1]);
    $first = McpToken::issue($this->admin(), 'a')['plain_token'];
    $second = McpToken::issue($this->admin(), 'b')['plain_token'];

    packageMcpList(['X-Api-Token' => $first])->assertOk();
    packageMcpList(['X-Api-Token' => $first])->assertTooManyRequests();
    packageMcpList(['X-Api-Token' => $second])->assertOk();
});

it('closes the tools themselves to users the gate refuses', function () {
    LookoutServer::actingAs($this->admin(false))->tool(ListIssuesTool::class, [])->assertHasErrors(['Admins only.']);
});

it('returns the connection settings with the client name', function () {
    $this->actingAs($this->admin());
    config(['app.url' => 'https://example.test', 'app.name' => 'Acme App']);

    $this->getJson('/lookout/api/mcp/config')->assertOk()
        ->assertJsonPath('endpoint', 'https://example.test/mcp/lookout')
        ->assertJsonPath('server_name', 'acme-app-lookout');
});
