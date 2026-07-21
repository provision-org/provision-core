<?php

use App\Contracts\CommandExecutor;
use App\Enums\ChatMessageRole;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Services\OpenClawChatService;
use Illuminate\Support\Facades\Storage;

function nativeChatFixture(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-native-test',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
        'session_key' => 'web:legacy-session',
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'content' => [['type' => 'text', 'text' => 'Hello from Provision']],
        'delivery_status' => 'queued',
    ]);

    return [$conversation, $message, $server];
}

test('native OpenClaw chat sends idempotently and reads the canonical reply from history', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")
            && str_contains($command, 'provision-chat:'.$message->id)
            && str_contains($command, 'Hello from Provision')))
        ->andReturn(json_encode(['runId' => 'run-native-1', 'status' => 'started']));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hello from Provision',
                    'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'not user-visible'],
                        ['type' => 'text', 'text' => '[[reply_to_current]] Native reply'],
                    ],
                    '__openclaw' => ['id' => 'reply-native-1'],
                ],
            ],
            'sessionInfo' => ['hasActiveRun' => false],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result)->toBe([
        'run_id' => 'run-native-1',
        'upstream_id' => 'openclaw:reply-native-1',
        'content' => [['type' => 'text', 'text' => 'Native reply']],
    ])->and($conversation->fresh()->session_key)
        ->toBe("agent:agent-native-test:dashboard:{$conversation->id}")
        ->and($message->fresh()->delivery_status)->toBe('running')
        ->and($message->fresh()->upstream_run_id)->toBe('run-native-1');
});

test('native OpenClaw chat stages every file in the agent workspace', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    Storage::disk('local')->put('chat-attachments/notes.txt', 'important notes');
    $message->update([
        'content' => [[
            'type' => 'file',
            'path' => 'chat-attachments/notes.txt',
            'fileName' => 'Quarterly Notes.txt',
            'mimeType' => 'text/plain',
        ]],
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'install -d -m 0700')))->andReturn('');
    $executor->shouldReceive('writeFile')
        ->once()
        ->with(Mockery::on(fn (string $path) => str_ends_with($path, '/01-quarterly-notes.txt')), 'important notes');
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'chmod 0600')))->andReturn('');
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")
            && str_contains($command, 'Quarterly Notes.txt')
            && str_contains($command, '01-quarterly-notes.txt')))
        ->andReturn(json_encode(['runId' => 'run-file', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                ['role' => 'assistant', 'content' => 'File received', '__openclaw' => ['id' => 'file-reply']],
            ],
        ]));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'rm -rf --')
            && str_contains($command, 'provision-chat-attachments')))
        ->andReturn('');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['content'])->toBe([['type' => 'text', 'text' => 'File received']]);
});

test('native OpenClaw chat keeps large attachments out of the gateway command argument', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    $contents = str_repeat('large-attachment-', 10_240);
    Storage::disk('local')->put('chat-attachments/large.txt', $contents);
    $message->update([
        'content' => [[
            'type' => 'file',
            'path' => 'chat-attachments/large.txt',
            'fileName' => 'Large Attachment.txt',
            'mimeType' => 'text/plain',
        ]],
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'install -d -m 0700')))->andReturn('');
    $executor->shouldReceive('writeFile')
        ->once()
        ->with(Mockery::on(fn (string $path) => str_ends_with($path, '/01-large-attachment.txt')), $contents);
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'chmod 0600')))->andReturn('');
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")
            && str_contains($command, '01-large-attachment.txt')
            && ! str_contains($command, '"attachments"')
            && strlen($command) < 100_000))
        ->andReturn(json_encode(['runId' => 'run-large-file', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                ['role' => 'assistant', 'content' => 'Large file received', '__openclaw' => ['id' => 'large-file-reply']],
            ],
        ]));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'rm -rf --')
            && str_contains($command, 'provision-chat-attachments')))
        ->andReturn('');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['content'])->toBe([['type' => 'text', 'text' => 'Large file received']]);
});

test('native OpenClaw chat preserves assistant media in the durable transcript', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-media', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Here is the image'],
                        [
                            'type' => 'image',
                            'source' => [
                                'media_type' => 'image/png',
                                'data' => base64_encode('png-bytes'),
                            ],
                            'fileName' => 'result.png',
                        ],
                    ],
                    '__openclaw' => ['id' => 'media-reply'],
                ],
            ],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['content'])->toHaveCount(2)
        ->and($result['content'][0])->toBe(['type' => 'text', 'text' => 'Here is the image'])
        ->and($result['content'][1])->toMatchArray([
            'type' => 'image',
            'disk' => 'local',
            'fileName' => 'result.png',
            'mimeType' => 'image/png',
        ]);
    Storage::disk('local')->assertExists($result['content'][1]['path']);
});

test('native OpenClaw chat aborts the active run when cancellation is requested', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturnUsing(function () use ($message): string {
            $message->update([
                'delivery_status' => 'aborted',
                'delivery_error' => 'Response stopped.',
            ]);

            return json_encode(['runId' => 'run-cancel', 'status' => 'started']);
        });
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.abort'")
            && str_contains($command, 'run-cancel')))
        ->andReturn(json_encode(['ok' => true, 'aborted' => true]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    expect(fn () => (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        cancelled: function () use ($message): bool {
            $message->refresh();

            return $message->delivery_status === 'aborted';
        },
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    ))->toThrow(RuntimeException::class, 'The response was stopped.');

    expect($message->fresh()->delivery_status)->toBe('aborted')
        ->and($message->fresh()->upstream_run_id)->toBeNull();
});

test('native OpenClaw chat does not send when cancellation is already durable', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $message->update(['delivery_status' => 'aborted']);
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldNotReceive('exec');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    expect(fn () => (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        cancelled: function () use ($message): bool {
            $message->refresh();

            return $message->delivery_status === 'aborted';
        },
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    ))->toThrow(RuntimeException::class, 'The response was stopped.');
});
