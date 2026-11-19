<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lookout\Models\McpToken;
use Lookout\Tests\Fixtures\MysqlUser;

function packageTokensMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_11_09_120000_create_mcp_monitoring_tokens_table.php';
}

function packageForeignKeysOf(string $connection, string $table): array
{
    return collect(Schema::connection($connection)->getForeignKeys($table))
        ->map(fn (array $key): string => implode(',', $key['columns']).'->'.$key['foreign_table'].'.'.implode(',', $key['foreign_columns']))
        ->all();
}

it('keeps the tokens next to users with a foreign key by default', function () {
    expect(Schema::hasTable('lookout_mcp_tokens'))->toBeTrue()
        ->and(packageForeignKeysOf('testing', 'lookout_mcp_tokens'))->toBe(['user_id->users.id'])
        ->and((new McpToken)->getConnectionName())->toBeNull();
});

it('puts the tokens on lookout.mcp.tokens_connection, indexed and without a foreign key', function () {
    config(['database.connections.tokens' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    config(['lookout.mcp.tokens_connection' => 'tokens']);

    packageTokensMigration()->up();

    $indexes = collect(Schema::connection('tokens')->getIndexes('lookout_mcp_tokens'))->pluck('columns')->all();

    expect(Schema::connection('tokens')->hasTable('lookout_mcp_tokens'))->toBeTrue()
        ->and(packageForeignKeysOf('tokens', 'lookout_mcp_tokens'))->toBe([])
        ->and($indexes)->toContain(['user_id', 'revoked_at']);

    ['plain_token' => $plain] = McpToken::issue($this->admin(), 'Claude Code');

    expect(DB::connection('tokens')->table('lookout_mcp_tokens')->count())->toBe(1)
        ->and(McpToken::findActive($plain)?->user?->email)->not->toBeNull();

    $this->postJson('/mcp/lookout', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['X-Api-Token' => $plain])->assertOk();
});

it('matches an INT UNSIGNED users.id on MySQL so the foreign key holds', function (?string $configured) {
    $database = (string) env('LOOKOUT_TEST_MYSQL_DATABASE', '');
    if ($database === '') {
        $this->markTestSkipped('Set LOOKOUT_TEST_MYSQL_DATABASE (and _HOST, _PORT, _USERNAME, _PASSWORD) to a local scratch MySQL database.');
    }

    config(['database.connections.lookout_mysql' => [
        'driver' => 'mysql',
        'host' => env('LOOKOUT_TEST_MYSQL_HOST', '127.0.0.1'),
        'port' => env('LOOKOUT_TEST_MYSQL_PORT', '3306'),
        'database' => $database,
        'username' => env('LOOKOUT_TEST_MYSQL_USERNAME', 'root'),
        'password' => env('LOOKOUT_TEST_MYSQL_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]]);
    config([
        'auth.providers.users.model' => MysqlUser::class,
        'lookout.mcp.tokens_connection' => 'lookout_mysql',
        'lookout.mcp.user_id_type' => $configured,
    ]);

    $schema = Schema::connection('lookout_mysql');
    $schema->dropIfExists('lookout_mcp_tokens');
    $schema->dropIfExists('users');
    $schema->create('users', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->string('email');
        $table->boolean('is_admin')->default(false);
        $table->timestamps();
    });

    try {
        packageTokensMigration()->up();

        expect($schema->getColumnType('lookout_mcp_tokens', 'user_id', true))->toBe('int unsigned')
            ->and(packageForeignKeysOf('lookout_mysql', 'lookout_mcp_tokens'))->toBe(['user_id->users.id']);

        $owner = MysqlUser::query()->create(['name' => 'A', 'email' => 'a@example.test', 'is_admin' => true]);
        McpToken::issue($owner, 'x');
        $owner->delete();

        expect(McpToken::query()->count())->toBe(0);
    } finally {
        $schema->dropIfExists('lookout_mcp_tokens');
        $schema->dropIfExists('users');
    }
})->with(['inferred from users.id' => null, 'configured' => 'int']);
