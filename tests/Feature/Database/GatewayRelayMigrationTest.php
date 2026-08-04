<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

test('Gateway relay migration tolerates a legacy chat table without a session key index', function () {
    $migration = require database_path('migrations/2026_08_03_120000_add_gateway_relay_state_to_chat_tables.php');
    $migration->down();

    $sessionKeyIndex = collect(Schema::getIndexes('chat_conversations'))
        ->first(fn (array $index): bool => ($index['unique'] ?? false) === true
            && ($index['columns'] ?? []) === ['session_key']);

    expect($sessionKeyIndex)->toBeArray();

    Schema::table('chat_conversations', function (Blueprint $table) use ($sessionKeyIndex): void {
        $table->dropUnique($sessionKeyIndex['name']);
    });

    expect(Schema::hasIndex('chat_conversations', ['session_key'], 'unique'))->toBeFalse();

    $migration->up();

    expect(Schema::hasIndex('chat_conversations', ['agent_id', 'session_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasColumn('chat_conversations', 'source'))->toBeTrue()
        ->and(Schema::hasColumn('chat_messages', 'last_gateway_event_at'))->toBeTrue()
        ->and(Schema::hasColumn('servers', 'daemon_version'))->toBeTrue();
});

test('Gateway relay migration drops a renamed legacy session key index', function () {
    $migration = require database_path('migrations/2026_08_03_120000_add_gateway_relay_state_to_chat_tables.php');
    $migration->down();

    $sessionKeyIndex = collect(Schema::getIndexes('chat_conversations'))
        ->first(fn (array $index): bool => ($index['unique'] ?? false) === true
            && ($index['columns'] ?? []) === ['session_key']);

    expect($sessionKeyIndex)->toBeArray();

    Schema::table('chat_conversations', function (Blueprint $table) use ($sessionKeyIndex): void {
        $table->dropUnique($sessionKeyIndex['name']);
        $table->unique('session_key', 'legacy_session_key_unique');
    });

    $migration->up();

    $sessionKeyIndexes = collect(Schema::getIndexes('chat_conversations'))
        ->filter(fn (array $index): bool => ($index['unique'] ?? false) === true
            && ($index['columns'] ?? []) === ['session_key']);

    expect($sessionKeyIndexes)->toBeEmpty()
        ->and(Schema::hasIndex('chat_conversations', ['agent_id', 'session_key'], 'unique'))->toBeTrue();
});

test('Gateway relay migration can safely converge an already migrated schema', function () {
    $migration = require database_path('migrations/2026_08_03_120000_add_gateway_relay_state_to_chat_tables.php');

    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('chat_conversations', ['session_key'], 'unique'))->toBeFalse()
        ->and(Schema::hasIndex('chat_conversations', ['agent_id', 'session_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasColumn('chat_conversations', 'source'))->toBeTrue()
        ->and(Schema::hasIndex('chat_messages', ['last_gateway_event_at']))->toBeTrue()
        ->and(Schema::hasColumn('servers', 'daemon_version'))->toBeTrue();
});
