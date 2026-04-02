<?php

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Server;
use App\Models\User;
use App\Services\SshService;
use Illuminate\Http\UploadedFile;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockSshForWorkspace(Server $server): Mockery\MockInterface
{
    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('connect')
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturnSelf();
    $mock->shouldReceive('disconnect');
    app()->instance(SshService::class, $mock);

    return $mock;
}

function createAgentWithServer(User $user): Agent
{
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    return Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);
}

test('index returns files and usage', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'))
        ->once();
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/find.*-printf/'))
        ->once()
        ->andReturn("f|1024|1709654400.0|readme.md\nd|4096|1709654400.0|docs\n");
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/du -sb/'))
        ->once()
        ->andReturn('5120');

    $response = $this->actingAs($user)->getJson(route('agents.workspace.index', $agent));

    $response->assertOk()
        ->assertJsonCount(2, 'files')
        ->assertJsonPath('usage', 5120)
        ->assertJsonPath('limit', 52_428_800);
});

test('index hides system files from listing', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'))
        ->once();
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/find.*-printf/'))
        ->once()
        ->andReturn(implode("\n", [
            'f|100|1709654400.0|AGENTS.md',
            'f|100|1709654400.0|TOOLS.md',
            'f|100|1709654400.0|HEARTBEAT.md',
            'f|100|1709654400.0|SOUL.md',
            'f|100|1709654400.0|IDENTITY.md',
            'f|100|1709654400.0|USER.md',
            'f|100|1709654400.0|BOOTSTRAP.md',
            'f|100|1709654400.0|.env',
            'd|4096|1709654400.0|agent',
            'd|4096|1709654400.0|workspace',
            'd|4096|1709654400.0|.openclaw',
            'd|4096|1709654400.0|memory',
            'd|4096|1709654400.0|projects',
            'd|4096|1709654400.0|sessions',
            'f|1024|1709654400.0|report.csv',
            'd|4096|1709654400.0|research',
            'f|512|1709654400.0|research/notes.md',
        ]));
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/du -sb/'))
        ->once()
        ->andReturn('5120');

    $response = $this->actingAs($user)->getJson(route('agents.workspace.index', $agent));

    $response->assertOk()
        ->assertJsonCount(4, 'files');

    $names = collect($response->json('files'))->pluck('name')->all();
    expect($names)->toBe(['workspace', 'report.csv', 'research', 'notes.md']);
});

test('index returns empty for agent without server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => null,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.workspace.index', $agent));

    $response->assertOk()
        ->assertJsonPath('files', [])
        ->assertJsonPath('usage', 0);
});

test('index returns 404 for agent on different team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignAgent = Agent::factory()->create();

    $response = $this->actingAs($user)->getJson(route('agents.workspace.index', $foreignAgent));

    $response->assertNotFound();
});

test('upload sends files via SFTP', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'))
        ->once();
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/du -sb/'))
        ->once()
        ->andReturn('0');
    $mock->shouldReceive('upload')
        ->once()
        ->with(Mockery::type('string'), Mockery::pattern('/agents\/.*\/test\.md$/'));

    $file = UploadedFile::fake()->create('test.md', 100);

    $response = $this->actingAs($user)->postJson(route('agents.workspace.upload', $agent), [
        'files' => [$file],
    ]);

    $response->assertOk()
        ->assertJsonPath('count', 1);
});

test('upload rejects files exceeding storage limit', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'));
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/du -sb/'))
        ->andReturn('52428700');

    $file = UploadedFile::fake()->create('big.md', 200);

    $response = $this->actingAs($user)->postJson(route('agents.workspace.upload', $agent), [
        'files' => [$file],
    ]);

    $response->assertStatus(422);
});

test('upload skips disallowed file extensions', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'));
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/du -sb/'))
        ->andReturn('0');
    $mock->shouldNotReceive('upload');

    $file = UploadedFile::fake()->create('malware.exe', 100);

    $response = $this->actingAs($user)->postJson(route('agents.workspace.upload', $agent), [
        'files' => [$file],
    ]);

    $response->assertOk()
        ->assertJsonPath('count', 0);
});

test('create folder creates directory on server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p.*docs/'))
        ->once();

    $response = $this->actingAs($user)->postJson(route('agents.workspace.folder', $agent), [
        'name' => 'docs',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('destroy deletes file on server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('exec')
        ->with(Mockery::pattern('/rm -rf.*readme\.md/'))
        ->once();

    $response = $this->actingAs($user)->deleteJson(route('agents.workspace.destroy', $agent), [
        'path' => 'readme.md',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('destroy blocks deletion of system files', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $response = $this->actingAs($user)->deleteJson(route('agents.workspace.destroy', $agent), [
        'path' => 'AGENTS.md',
    ]);

    $response->assertForbidden();

    $response = $this->actingAs($user)->deleteJson(route('agents.workspace.destroy', $agent), [
        'path' => '.env',
    ]);

    $response->assertForbidden();

    $response = $this->actingAs($user)->deleteJson(route('agents.workspace.destroy', $agent), [
        'path' => 'agent',
    ]);

    $response->assertForbidden();

    $response = $this->actingAs($user)->deleteJson(route('agents.workspace.destroy', $agent), [
        'path' => 'sessions',
    ]);

    $response->assertForbidden();
});

test('destroy rejects empty path', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
    ]);

    $response = $this->actingAs($user)->deleteJson(route('agents.workspace.destroy', $agent), [
        'path' => '',
    ]);

    $response->assertStatus(422);
});

test('download streams file content', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = createAgentWithServer($user);

    $mock = mockSshForWorkspace($agent->server);
    $mock->shouldReceive('readFile')
        ->with(Mockery::pattern('/agents\/.*\/readme\.md$/'))
        ->once()
        ->andReturn('# Hello World');

    $response = $this->actingAs($user)->get(route('agents.workspace.download', [
        'agent' => $agent,
        'path' => 'readme.md',
    ]));

    $response->assertOk();
    expect($response->streamedContent())->toBe('# Hello World');
});
