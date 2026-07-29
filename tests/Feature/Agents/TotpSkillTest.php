<?php

use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function plainAgent(Team $team, Server $server): Agent
{
    return Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-totp-test',
        'system_prompt' => 'You are helpful.',
    ]);
}

// --- Install script (agent-create path) ---

test('install script deploys the totp skill for every agent (no email needed)', function () {
    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    // A plain agent with NO email connection still gets the 2FA skill.
    $agent = plainAgent($team, $server);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->toContain('/skills/totp/SKILL.md')
        ->toContain('/skills/totp/totp_cli.py')
        ->toContain('chmod +x /root/.openclaw/agents/agent-totp-test/skills/totp/totp_cli.py')
        // Secret store dir is created and locked down.
        ->toContain('mkdir -p /root/.openclaw/agents/agent-totp-test/secrets')
        ->toContain('chmod 700 /root/.openclaw/agents/agent-totp-test/secrets');
});

test('agent env points at the totp cli and store but never carries a secret', function () {
    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = plainAgent($team, $server);

    $env = AgentInstallScriptService::buildAgentEnv($agent);

    expect($env)
        ->toContain('TOTP_CLI=/root/.openclaw/agents/agent-totp-test/skills/totp/totp_cli.py')
        ->toContain('TOTP_STORE=/root/.openclaw/agents/agent-totp-test/secrets/totp.json')
        // The secret store path is here, but never a secret value.
        ->not->toContain('TOTP_SECRET');
});

// --- The CLI itself (RFC 6238 correctness) ---

test('totp_cli generates RFC 6238 codes and never leaks secrets in list', function () {
    if (! trim((string) shell_exec('command -v python3'))) {
        $this->markTestSkipped('python3 not available');
    }

    $cli = resource_path('skills/totp/totp_cli.py');
    $store = sys_get_temp_dir().'/totp-test-'.uniqid().'.json';
    $env = 'TOTP_STORE='.escapeshellarg($store).' ';

    // RFC 6238 SHA1 test secret = ASCII "12345678901234567890".
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    shell_exec($env.'python3 '.escapeshellarg($cli).' add acme --secret '.escapeshellarg($secret).' --digits 8 2>&1');

    // Reproduce the RFC vector by generating for a fixed counter via a tiny probe.
    $probe = escapeshellarg(
        "import sys; sys.path.insert(0, '".resource_path('skills/totp')."'); ".
        "import totp_cli as t; ".
        "print(t.generate_code({'secret':'{$secret}','digits':8,'period':30,'algorithm':'SHA1'}, 59))"
    );
    $code = trim((string) shell_exec('python3 -c '.$probe));
    expect($code)->toBe('94287082');

    // `list` must show the label but never the secret material.
    $list = (string) shell_exec($env.'python3 '.escapeshellarg($cli).' list 2>&1');
    expect($list)
        ->toContain('acme')
        ->not->toContain($secret);

    @unlink($store);
});
