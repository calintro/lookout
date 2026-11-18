<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal tokens of the Lookout MCP server, in lookout.mcp.tokens_table. The file keeps the name it had in the app
 * Lookout was extracted from, so apps that already ran it do not run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(self::table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->boolean('can_resolve')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::table());
    }

    private static function table(): string
    {
        return (string) (config('lookout.mcp.tokens_table') ?: 'lookout_mcp_tokens');
    }
};
