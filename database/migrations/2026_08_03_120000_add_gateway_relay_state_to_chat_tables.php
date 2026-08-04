<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropUniqueIndexesForColumns('chat_conversations', ['session_key']);

        if (! Schema::hasIndex('chat_conversations', ['agent_id', 'session_key'], 'unique')) {
            Schema::table('chat_conversations', function (Blueprint $table): void {
                $table->unique(['agent_id', 'session_key']);
            });
        }

        $this->addChatConversationColumns();
        $this->addChatMessageColumns();
        $this->addServerColumns();
    }

    public function down(): void
    {
        $this->dropServerColumns();
        $this->dropChatMessageColumns();
        $this->dropUniqueIndexesForColumns('chat_conversations', ['agent_id', 'session_key']);

        if (! Schema::hasIndex('chat_conversations', ['session_key'], 'unique')) {
            Schema::table('chat_conversations', function (Blueprint $table): void {
                $table->unique('session_key');
            });
        }

        $columns = collect(['source', 'source_channel', 'is_read_only', 'last_reconciled_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('chat_conversations', $column))
            ->all();

        if ($columns !== []) {
            Schema::table('chat_conversations', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function addChatConversationColumns(): void
    {
        $hasSource = Schema::hasColumn('chat_conversations', 'source');
        $hasSourceChannel = Schema::hasColumn('chat_conversations', 'source_channel');
        $hasReadOnly = Schema::hasColumn('chat_conversations', 'is_read_only');
        $hasLastReconciled = Schema::hasColumn('chat_conversations', 'last_reconciled_at');

        if ($hasSource && $hasSourceChannel && $hasReadOnly && $hasLastReconciled) {
            return;
        }

        Schema::table('chat_conversations', function (Blueprint $table) use ($hasSource, $hasSourceChannel, $hasReadOnly, $hasLastReconciled): void {
            if (! $hasSource) {
                $table->string('source')->default('dashboard')->after('session_key');
            }
            if (! $hasSourceChannel) {
                $table->string('source_channel')->nullable()->after('source');
            }
            if (! $hasReadOnly) {
                $table->boolean('is_read_only')->default(false)->after('source_channel');
            }
            if (! $hasLastReconciled) {
                $table->timestamp('last_reconciled_at')->nullable()->after('last_message_at');
            }
        });
    }

    private function addChatMessageColumns(): void
    {
        $hasSequence = Schema::hasColumn('chat_messages', 'upstream_event_sequence');
        $hasLastEvent = Schema::hasColumn('chat_messages', 'last_gateway_event_at');

        if (! $hasSequence || ! $hasLastEvent) {
            Schema::table('chat_messages', function (Blueprint $table) use ($hasSequence, $hasLastEvent): void {
                if (! $hasSequence) {
                    $table->unsignedBigInteger('upstream_event_sequence')->nullable()->after('upstream_run_id');
                }
                if (! $hasLastEvent) {
                    $table->timestamp('last_gateway_event_at')->nullable()->after('upstream_event_sequence');
                }
            });
        }

        if (! Schema::hasIndex('chat_messages', ['last_gateway_event_at'])) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->index('last_gateway_event_at');
            });
        }
    }

    private function addServerColumns(): void
    {
        $hasVersion = Schema::hasColumn('servers', 'daemon_version');
        $hasCapabilities = Schema::hasColumn('servers', 'daemon_capabilities');
        $hasActiveRuns = Schema::hasColumn('servers', 'daemon_active_runs');

        if ($hasVersion && $hasCapabilities && $hasActiveRuns) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) use ($hasVersion, $hasCapabilities, $hasActiveRuns): void {
            if (! $hasVersion) {
                $table->string('daemon_version')->nullable()->after('daemon_token');
            }
            if (! $hasCapabilities) {
                $table->json('daemon_capabilities')->nullable()->after('daemon_version');
            }
            if (! $hasActiveRuns) {
                $table->json('daemon_active_runs')->nullable()->after('daemon_capabilities');
            }
        });
    }

    private function dropServerColumns(): void
    {
        $columns = collect(['daemon_version', 'daemon_capabilities', 'daemon_active_runs'])
            ->filter(fn (string $column): bool => Schema::hasColumn('servers', $column))
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function dropChatMessageColumns(): void
    {
        if (Schema::hasIndex('chat_messages', ['last_gateway_event_at'])) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropIndex(['last_gateway_event_at']);
            });
        }

        $columns = collect(['upstream_event_sequence', 'last_gateway_event_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('chat_messages', $column))
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    /**
     * Legacy production databases may have renamed or removed an index while
     * rebuilding ULID columns. Resolve the actual index name instead of
     * assuming Laravel's generated name still exists.
     *
     * @param  list<string>  $columns
     */
    private function dropUniqueIndexesForColumns(string $tableName, array $columns): void
    {
        collect(Schema::getIndexes($tableName))
            ->filter(fn (array $index): bool => ($index['unique'] ?? false) === true
                && ($index['primary'] ?? false) === false
                && ($index['columns'] ?? []) === $columns
                && is_string($index['name'] ?? null))
            ->pluck('name')
            ->each(function (string $indexName) use ($tableName): void {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropUnique($indexName);
                });
            });
    }
};
