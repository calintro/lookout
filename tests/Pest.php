<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lookout\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * The NightOwl tables Lookout writes to, on the test connection, with the columns the agent's migrations give them.
 */
function nightowlTables(): void
{
    config(['lookout.connection' => 'testing', 'cache.default' => 'array', 'app.env' => 'production', 'nightowl.environment' => null]);
    $schema = Schema::connection('testing');
    $schema->create('nightowl_settings', function (Blueprint $t): void {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    $schema->create('nightowl_alert_channels', function (Blueprint $t): void {
        $t->id();
        $t->string('name');
        $t->string('type');
        $t->text('config');
        $t->boolean('enabled')->default(true);
        $t->timestamps();
    });
    $schema->create('nightowl_issues', function (Blueprint $t): void {
        $t->id();
        $t->string('type');
        $t->string('subtype')->nullable();
        $t->string('environment')->nullable();
        $t->string('status')->default('open');
        $t->string('exception_class')->nullable();
        $t->text('exception_message')->nullable();
        $t->string('group_hash')->nullable();
        $t->timestamp('first_seen_at')->nullable();
        $t->timestamp('last_seen_at')->nullable();
        $t->unsignedInteger('occurrences_count')->default(0);
        $t->unsignedInteger('users_count')->default(0);
        $t->unsignedInteger('threshold_ms')->nullable();
        $t->unsignedInteger('triggered_duration_ms')->nullable();
        $t->string('priority')->nullable();
        $t->string('assigned_to')->nullable();
        $t->text('description')->nullable();
        $t->timestamps();
    });
    $schema->create('nightowl_issue_activity', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('issue_id');
        $t->string('user_id')->nullable();
        $t->string('user_name')->nullable();
        $t->string('actor_type', 20)->default('user');
        $t->json('actor_meta')->nullable();
        $t->string('action', 50);
        $t->string('old_value')->nullable();
        $t->string('new_value')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    $schema->create('nightowl_issue_comments', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('issue_id');
        $t->string('user_id')->nullable();
        $t->string('user_name')->nullable();
        $t->string('user_email')->nullable();
        $t->text('body');
        $t->string('actor_type', 20)->default('user');
        $t->json('actor_meta')->nullable();
        $t->timestamps();
    });
}
