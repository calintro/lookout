<?php

namespace Lookout\Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Lookout\Http\Middleware\AuthenticateMcpToken;
use Lookout\LookoutServiceProvider;
use Lookout\Models\McpToken;
use Lookout\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * lookout.mcp.middleware replaces the default ThrottleRequests after the token check. The default (null) is covered
 * here too, since the app the package was extracted from relies on it.
 */
class McpMiddlewareTest extends TestCase
{
    /** @var list<mixed>|null */
    public static ?array $middleware = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (self::$middleware !== null) {
            $app['config']->set('lookout.mcp.middleware', self::$middleware);
        }

        $app['router']->aliasMiddleware('lookout-test-header', McpTestHeaderMiddleware::class);
    }

    protected function tearDown(): void
    {
        self::$middleware = null;

        parent::tearDown();
    }

    public function test_the_default_keeps_the_per_credential_throttle(): void
    {
        $this->assertSame([AuthenticateMcpToken::class, ThrottleRequests::class.':lookout-mcp'], $this->lookoutMiddleware());
    }

    public function test_an_app_mcp_array_written_before_the_key_existed_keeps_its_throttle(): void
    {
        config(['lookout.mcp' => ['enabled' => true, 'path' => 'mcp/monitoring', 'rate_limiter' => 'mcp-monitoring', 'tokens_table' => 'mcp_monitoring_tokens']]);

        $this->assertSame([ThrottleRequests::class.':mcp-monitoring'], LookoutServiceProvider::mcpMiddleware());
        $this->assertNull((new McpToken)->getConnectionName());
        $this->assertSame('mcp_monitoring_tokens', (new McpToken)->getTable());
    }

    public function test_a_configured_list_replaces_the_throttle(): void
    {
        self::$middleware = ['lookout-test-header'];
        $this->refreshApplication();
        $this->setUpTheTestEnvironmentAgain();

        $this->assertSame([AuthenticateMcpToken::class, 'lookout-test-header'], $this->lookoutMiddleware());

        config(['lookout.mcp.per_minute' => 1]);
        $plain = McpToken::issue($this->admin(), 'a')['plain_token'];

        foreach ([1, 2] as $call) {
            $this->postJson('/mcp/lookout', ['jsonrpc' => '2.0', 'id' => $call, 'method' => 'tools/list'], ['X-Api-Token' => $plain])
                ->assertOk()
                ->assertHeader('X-Lookout-Test', 'yes');
        }
    }

    public function test_an_empty_list_drops_the_throttle(): void
    {
        self::$middleware = [];
        $this->refreshApplication();
        $this->setUpTheTestEnvironmentAgain();

        $this->assertSame([AuthenticateMcpToken::class], $this->lookoutMiddleware());
    }

    /**
     * The route's middleware from the token check on (laravel/mcp puts its own in front).
     *
     * @return list<mixed>
     */
    private function lookoutMiddleware(): array
    {
        $middleware = $this->mcpRoute()->middleware();

        return array_values(array_slice($middleware, (int) array_search(AuthenticateMcpToken::class, $middleware, true)));
    }

    private function mcpRoute(): Route
    {
        return collect(Router::getRoutes()->getRoutes())
            ->first(fn (Route $route): bool => $route->uri() === 'mcp/lookout' && in_array('POST', $route->methods(), true));
    }

    private function setUpTheTestEnvironmentAgain(): void
    {
        $this->artisan('migrate:fresh');
        \Illuminate\Support\Facades\Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
        $this->artisan('migrate');
        \Illuminate\Support\Facades\Gate::define('viewLookout', fn ($user): bool => (bool) $user->is_admin);
    }
}

class McpTestHeaderMiddleware
{
    public function handle($request, \Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Lookout-Test', 'yes');

        return $response;
    }
}
