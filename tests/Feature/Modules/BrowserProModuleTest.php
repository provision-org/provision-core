<?php

use App\Contracts\Modules\AgentProxyProvider;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\BrowserPro\BrowserProModule;
use Provision\BrowserPro\Services\ProxyConfigService;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(BrowserProModule::class)) {
        $this->markTestSkipped('BrowserPro module not installed');
    }
});

test('module registry collects install script sections from registered modules', function () {
    $registry = new ModuleRegistry;

    $proxyConfigService = new ProxyConfigService;
    $module = new BrowserProModule($proxyConfigService);
    $registry->register($module);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-test',
    ]);

    config(['decodo.username' => 'testuser', 'decodo.password' => 'testpass']);

    $sections = $registry->installScriptSections($agent);

    expect($sections)->toHaveCount(1);
    expect($sections[0])
        ->toContain('gost')
        ->toContain('pac-server')
        ->toContain('gost-proxy')
        ->toContain('systemctl daemon-reload');
});

test('browser pro module builds proxy script with gost and PAC', function () {
    config(['decodo.username' => 'testuser', 'decodo.password' => 'testpass']);

    $proxyConfigService = new ProxyConfigService;
    $module = new BrowserProModule($proxyConfigService);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-proxy-test',
    ]);

    $script = $module->buildProxyScript($agent);

    expect($script)
        ->toContain('gost_3.2.6_linux_amd64')
        ->toContain('proxy.pac')
        ->toContain('gost.yaml')
        ->toContain('pac-server.service')
        ->toContain('gost-proxy.service')
        ->toContain('systemctl enable --now pac-server gost-proxy');
});

test('browser pro module reports proxy capability', function () {
    $module = new BrowserProModule(new ProxyConfigService);

    expect($module->name())->toBe('browser-pro');
    expect($module->capabilities())->toBe(['proxy']);
});

test('module registry has capability check', function () {
    $registry = new ModuleRegistry;

    expect($registry->hasCapability('proxy'))->toBeFalse();

    $registry->register(new BrowserProModule(new ProxyConfigService));

    expect($registry->hasCapability('proxy'))->toBeTrue();
    expect($registry->hasCapability('email'))->toBeFalse();
});

test('install script includes proxy setup when browser-pro module is registered', function () {
    config(['decodo.username' => 'testuser', 'decodo.password' => 'testpass']);

    // Manually register the module (since config is set after boot)
    $proxyModule = new BrowserProModule(app(ProxyConfigService::class));
    app(ModuleRegistry::class)->register($proxyModule);
    app()->instance(AgentProxyProvider::class, $proxyModule);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'name' => 'Atlas',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");

    $script = $response->getContent();
    expect($script)
        ->toContain('gost')
        ->toContain('pac-server')
        ->toContain('proxy-pac-url');
});

test('install script excludes proxy setup when no decodo config', function () {
    config(['decodo.username' => null, 'decodo.password' => null]);

    // Ensure the proxy provider is NOT bound
    app()->offsetUnset(AgentProxyProvider::class);

    // Re-register module registry without BrowserProModule
    app()->singleton(ModuleRegistry::class);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'name' => 'Atlas',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");

    $script = $response->getContent();
    expect($script)
        ->not->toContain('gost')
        ->not->toContain('pac-server')
        ->not->toContain('proxy-pac-url');
});

test('module registry returns empty sections when no modules registered', function () {
    $registry = new ModuleRegistry;

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    expect($registry->installScriptSections($agent))->toBe([]);
});
