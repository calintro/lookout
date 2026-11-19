<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lookout\Lookout;

/**
 * Personal tokens of the Lookout MCP server, in lookout.mcp.tokens_table on lookout.mcp.tokens_connection. The file
 * keeps the name it had in the app Lookout was extracted from, so apps that already ran it do not run it again.
 *
 * user_id matches the users key (lookout.mcp.user_id_type, or read from the users table), so the foreign key also
 * works where users.id is INT UNSIGNED. On another connection than the users table there is no foreign key, only
 * the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = self::users();
        $sameConnection = self::connection() === $users['connection'];
        $type = self::userIdType($users, $sameConnection);

        Schema::connection(self::connection())->create(self::table(), function (Blueprint $table) use ($users, $sameConnection, $type): void {
            $table->id();
            $type === 'int' ? $table->unsignedInteger('user_id') : $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->boolean('can_resolve')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);

            if ($sameConnection) {
                $table->foreign('user_id')->references($users['key'])->on($users['table'])->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection(self::connection())->dropIfExists(self::table());
    }

    private static function table(): string
    {
        return (string) (config('lookout.mcp.tokens_table') ?: 'lookout_mcp_tokens');
    }

    private static function connection(): string
    {
        $connection = config('lookout.mcp.tokens_connection');

        return is_string($connection) && $connection !== '' ? $connection : (string) config('database.default');
    }

    /**
     * @return array{connection: string, table: string, key: string}
     */
    private static function users(): array
    {
        $class = Lookout::userModel();
        /** @var Model $user */
        $user = new $class;

        return [
            'connection' => (string) ($user->getConnectionName() ?: config('database.default')),
            'table' => $user->getTable(),
            'key' => $user->getKeyName(),
        ];
    }

    /**
     * 'bigint' or 'int': lookout.mcp.user_id_type, else the users key as the database reports it (only when it is
     * reachable on the same connection), else 'bigint'.
     *
     * @param  array{connection: string, table: string, key: string}  $users
     */
    private static function userIdType(array $users, bool $sameConnection): string
    {
        $configured = config('lookout.mcp.user_id_type');

        if (in_array($configured, ['bigint', 'int'], true)) {
            return $configured;
        }

        if (! $sameConnection || DB::connection($users['connection'])->getDriverName() === 'sqlite'
            || ! Schema::connection($users['connection'])->hasColumn($users['table'], $users['key'])) {
            return 'bigint';
        }

        $definition = strtolower(Schema::connection($users['connection'])->getColumnType($users['table'], $users['key'], true));

        return ! str_contains($definition, 'big') && ! str_contains($definition, 'int8') && preg_match('/int/', $definition) === 1
            ? 'int'
            : 'bigint';
    }
};
