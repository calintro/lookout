<?php

namespace Lookout\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\McpServiceProvider;
use Lookout\LookoutServiceProvider;
use Lookout\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The package in a bare Laravel app: SQLite in memory for users and MCP tokens, no NightOwl database (the telemetry
 * endpoints are covered by the consuming app's suite, against a real NightOwl schema).
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, LookoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('lookout.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/login', fn () => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewLookout', fn (User $user): bool => $user->is_admin);
    }

    protected function admin(bool $admin = true): User
    {
        static $n = 0;
        $n++;

        return User::query()->create(['name' => "User {$n}", 'email' => "user{$n}@example.test", 'is_admin' => $admin]);
    }
}
