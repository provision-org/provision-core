<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original chat_conversations migration declared user_id as foreignId
 * (BIGINT), but users use ULIDs. Inserts truncated to zero and crashed every
 * chat send with "Data truncated for column 'user_id'".
 *
 * Idempotent migration: production was in an inconsistent state — user_id
 * was BIGINT but neither the FK nor the composite index from the original
 * migration actually existed. Drop conditionally, switch the column type,
 * then (re)create the FK and index.
 */
return new class extends Migration
{
    private const TABLE = 'chat_conversations';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Each drop is its own ALTER TABLE so a missing constraint doesn't
        // poison sibling operations. Production shipped with a partial
        // schema; the migration must converge regardless of starting state.
        $this->safelyAlter(fn (Blueprint $t) => $t->dropIndex(['agent_id', 'user_id']));
        $this->safelyAlter(fn (Blueprint $t) => $t->dropForeign(['user_id']));

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->char('user_id', 26)->change();
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['agent_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->safelyAlter(fn (Blueprint $t) => $t->dropIndex(['agent_id', 'user_id']));
        $this->safelyAlter(fn (Blueprint $t) => $t->dropForeign(['user_id']));

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->change();
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['agent_id', 'user_id']);
        });
    }

    private function safelyAlter(callable $callback): void
    {
        try {
            Schema::table(self::TABLE, fn (Blueprint $table) => $callback($table));
        } catch (Throwable) {
            // Constraint doesn't exist yet; that's fine for an idempotent fix.
        }
    }
};
