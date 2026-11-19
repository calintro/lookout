<?php

namespace Lookout\Tests\Feature;

use Lookout\Tests\TestCase;

/**
 * Lookout appends its own routes to nightowl.ignore_routes at boot, so the agent drops its requests (tokens, webhook
 * URLs and secrets) instead of showing them to every viewer.
 */
class IgnoreOwnRoutesTest extends TestCase
{
    /** @var array<string, mixed> */
    public static array $config = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach (self::$config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function tearDown(): void
    {
        self::$config = [];

        parent::tearDown();
    }

    private function boot(array $config): void
    {
        self::$config = $config;
        $this->refreshApplication();
        $this->artisan('migrate:fresh'); // tearDown rolls the package migration back on the fresh app
    }

    public function test_it_appends_the_web_and_mcp_paths_to_the_apps_list(): void
    {
        $this->boot(['nightowl.ignore_routes' => ['horizon/*', '*livewire.update']]);

        $this->assertSame(['horizon/*', '*livewire.update', 'lookout', 'lookout/*', 'mcp/lookout'], config('nightowl.ignore_routes'));
    }

    public function test_it_reads_a_comma_separated_string_and_follows_configured_paths(): void
    {
        $this->boot(['nightowl.ignore_routes' => 'horizon/*, _debugbar/*', 'lookout.path' => '/admin/monitoring/', 'lookout.mcp.path' => '/mcp/ops']);

        $this->assertSame(['horizon/*', '_debugbar/*', 'admin/monitoring', 'admin/monitoring/*', 'mcp/ops'], config('nightowl.ignore_routes'));
    }

    public function test_it_does_not_duplicate_a_pattern_the_app_already_has(): void
    {
        $this->boot(['nightowl.ignore_routes' => ['lookout/*']]);

        $this->assertSame(['lookout/*', 'lookout', 'mcp/lookout'], config('nightowl.ignore_routes'));
    }

    public function test_it_can_be_turned_off(): void
    {
        $this->boot(['lookout.ignore_own_routes' => false, 'nightowl.ignore_routes' => ['horizon/*']]);

        $this->assertSame(['horizon/*'], config('nightowl.ignore_routes'));
    }

    public function test_an_empty_path_never_becomes_a_catch_all(): void
    {
        $this->boot(['lookout.path' => '/', 'lookout.mcp.enabled' => false, 'nightowl.ignore_routes' => []]);

        $this->assertSame([], config('nightowl.ignore_routes'));
    }
}
