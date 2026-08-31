<?php

use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use App\Jobs\ProvisionDockerServerJob;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Server;
use App\Models\User;
use App\Services\DockerExecutor;
use App\Services\OpenClawDefaultsService;
use Illuminate\Support\Facades\Bus;

test('Docker provisioning persists and configures a token-authenticated OpenClaw Gateway', function () {
    Bus::fake([UpdateEnvOnServerJob::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam->update(['harness_type' => HarnessType::OpenClaw]);
    $server = Server::factory()->create([
        'team_id' => $user->currentTeam->id,
        'cloud_provider' => CloudProvider::Docker,
    ]);

    $openClawConfig = null;
    $executor = Mockery::mock(DockerExecutor::class);
    $executor->shouldReceive('exec')->times(5)->andReturn('');
    $executor->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/openclaw.json', Mockery::on(function (string $contents) use (&$openClawConfig): bool {
            $openClawConfig = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return true;
        }));
    $executor->shouldReceive('writeFile')
        ->once()
        ->with('/etc/provisiond/config.json', Mockery::type('string'));

    $defaults = Mockery::mock(OpenClawDefaultsService::class);
    $defaults->shouldReceive('buildDefaults')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn([]);
    $defaults->shouldReceive('buildMemoryConfig')->withArgs(fn (Server $value) => $value->is($server))->andReturn(['search' => ['enabled' => false]]);

    (new ProvisionDockerServerJob($server))->handle($executor, $defaults);

    $gatewayToken = $server->fresh()->gateway_token;
    expect($gatewayToken)->toBeString()->not->toBeEmpty()
        ->and($openClawConfig['gateway']['auth'])->toBe([
            'mode' => 'token',
            'token' => $gatewayToken,
        ]);

    Bus::assertDispatched(UpdateEnvOnServerJob::class);
});
