<?php

use App\Contracts\CommandExecutor;
use App\Models\Server;
use App\Support\OpenClawGatewayEndpoint;
use Tests\TestCase;

uses(TestCase::class);

final class RecordingOpenClawGatewayExecutor implements CommandExecutor
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $writes = [];

    public ?string $failWhenCommandContains = null;

    public function __construct(public string $currentCaddyfile = '') {}

    public function exec(string $command): string
    {
        $this->commands[] = $command;

        if ($this->failWhenCommandContains !== null && str_contains($command, $this->failWhenCommandContains)) {
            throw new RuntimeException('Simulated command failure.');
        }

        if (str_starts_with($command, 'if [ -f ')) {
            return $this->currentCaddyfile;
        }

        return '';
    }

    public function execWithRetry(string $command, int $maxAttempts = 3, int $baseDelayMs = 2000): string
    {
        return $this->exec($command);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->writes[$path] = $content;
    }

    public function readFile(string $path): string
    {
        return $this->currentCaddyfile;
    }

    public function execScript(string $script): string
    {
        return $this->exec($script);
    }
}

function openClawGatewayServer(string $ipAddress = '203.0.113.42'): Server
{
    return new Server(['ipv4_address' => $ipAddress]);
}

it('builds the dedicated TLS WebSocket endpoint without exposing the gateway port', function () {
    $server = openClawGatewayServer();
    $caddyfile = OpenClawGatewayEndpoint::caddyfile($server);

    expect(OpenClawGatewayEndpoint::hostname($server))->toBe('gateway.203-0-113-42.sslip.io')
        ->and(OpenClawGatewayEndpoint::wssUrl($server))->toBe('wss://gateway.203-0-113-42.sslip.io')
        ->and(OpenClawGatewayEndpoint::trustedProxies())->toBe(['127.0.0.1', '::1'])
        ->and(OpenClawGatewayEndpoint::allowedOrigins($server))->toBe(['https://gateway.203-0-113-42.sslip.io'])
        ->and($caddyfile)->toContain('203-0-113-42.sslip.io {')
        ->toContain('on_demand_tls')
        ->toContain('ask '.rtrim((string) config('app.url'), '/').'/api/caddy/ask')
        ->toContain('import /etc/caddy/sites/*.caddy')
        ->toContain('gateway.203-0-113-42.sslip.io {')
        ->toContain("`header({'Connection':'*Upgrade*','Upgrade':'websocket'}) || header({':protocol':'websocket'})`")
        ->toContain('reverse_proxy 127.0.0.1:18789')
        ->toContain('respond 404')
        ->not->toContain('0.0.0.0:18789');
});

it('rejects a server without a valid IPv4 address', function (string $ipAddress) {
    expect(fn () => OpenClawGatewayEndpoint::wssUrl(openClawGatewayServer($ipAddress)))
        ->toThrow(InvalidArgumentException::class, 'valid server IPv4 address');
})->with(['', 'not-an-ip', '203.0.113.42.example.com']);

it('can build the shared root config without a mobile gateway for non OpenClaw servers', function () {
    $caddyfile = OpenClawGatewayEndpoint::caddyfile(openClawGatewayServer(), false);

    expect($caddyfile)
        ->toContain('on_demand_tls')
        ->toContain('import /etc/caddy/sites/*.caddy')
        ->toContain('203-0-113-42.sslip.io {')
        ->not->toContain('gateway.203-0-113-42.sslip.io {');
});

it('validates and reloads an already configured endpoint', function () {
    $server = openClawGatewayServer();
    $executor = new RecordingOpenClawGatewayExecutor(OpenClawGatewayEndpoint::caddyfile($server)."\n");

    OpenClawGatewayEndpoint::ensureConfigured($server, $executor);

    $candidate = array_key_first($executor->writes);
    $transaction = $executor->commands[4];

    expect($candidate)->toStartWith('/etc/caddy/Caddyfile.provision-mobile-')
        ->and($executor->writes[$candidate])->toBe(OpenClawGatewayEndpoint::caddyfile($server)."\n")
        ->and($executor->commands[0])->toBe('openclaw config set gateway.trustedProxies \'["127.0.0.1","::1"]\' --strict-json')
        ->and($executor->commands[1])->toBe('mkdir -p /etc/caddy/conf.d /etc/caddy/sites')
        ->and($executor->commands[2])->toBe("chmod 0644 '{$candidate}'")
        ->and($executor->commands[3])->toBe("caddy validate --config '{$candidate}' --adapter caddyfile")
        ->and($transaction)->toContain("exec 9>'".OpenClawGatewayEndpoint::CADDY_LOCK_FILE."'")
        ->toContain('flock -x 9')
        ->toContain("cp -p '/etc/caddy/Caddyfile' '/etc/caddy/Caddyfile.provision-previous'")
        ->toContain("mv '{$candidate}' '/etc/caddy/Caddyfile'")
        ->toContain("caddy validate --config '/etc/caddy/Caddyfile' --adapter caddyfile")
        ->toContain('systemctl reload caddy')
        ->and($executor->commands[5])->toBe("rm -f '{$candidate}'");
});

it('validates and atomically reloads a changed endpoint', function () {
    $server = openClawGatewayServer();
    $executor = new RecordingOpenClawGatewayExecutor("legacy.example.test {\n    respond 404\n}\n");

    OpenClawGatewayEndpoint::ensureConfigured($server, $executor);

    $candidate = array_key_first($executor->writes);
    $transaction = $executor->commands[4];

    expect($candidate)->toStartWith('/etc/caddy/Caddyfile.provision-mobile-')
        ->and($executor->writes[$candidate])->toBe(OpenClawGatewayEndpoint::caddyfile($server)."\n")
        ->and($transaction)->toContain("cp -p '/etc/caddy/Caddyfile' '/etc/caddy/Caddyfile.provision-previous'")
        ->toContain("touch '/etc/caddy/Caddyfile.provision-previous-absent'")
        ->toContain("mv '{$candidate}' '/etc/caddy/Caddyfile'")
        ->toContain("cp -p '/etc/caddy/Caddyfile.provision-previous' '/etc/caddy/Caddyfile'")
        ->toContain("caddy validate --config '/etc/caddy/Caddyfile' --adapter caddyfile")
        ->toContain('systemctl reload caddy')
        ->not->toContain("rm -f '/etc/caddy/Caddyfile.provision-previous'");
});

it('keeps the active Caddyfile and removes the candidate when validation fails', function () {
    $executor = new RecordingOpenClawGatewayExecutor('stale');
    $executor->failWhenCommandContains = 'caddy validate';

    expect(fn () => OpenClawGatewayEndpoint::ensureConfigured(openClawGatewayServer(), $executor))
        ->toThrow(RuntimeException::class, 'Simulated command failure.');

    expect($executor->commands)->toHaveCount(5)
        ->and($executor->commands[4])->toContain("rm -f '/etc/caddy/Caddyfile.provision-mobile-")
        ->not->toContain('systemctl reload caddy');
});

it('retains the prior Caddyfile backup when mutation or rollback has an ambiguous outcome', function () {
    $executor = new RecordingOpenClawGatewayExecutor('stale');
    $executor->failWhenCommandContains = 'systemctl reload caddy';

    expect(fn () => OpenClawGatewayEndpoint::ensureConfigured(openClawGatewayServer(), $executor))
        ->toThrow(RuntimeException::class, 'Simulated command failure.');

    $transaction = $executor->commands[4];

    expect($transaction)
        ->toContain("exec 9>'".OpenClawGatewayEndpoint::CADDY_LOCK_FILE."'")
        ->toContain("cp -p '/etc/caddy/Caddyfile' '/etc/caddy/Caddyfile.provision-previous'")
        ->toContain("cp -p '/etc/caddy/Caddyfile.provision-previous' '/etc/caddy/Caddyfile'")
        ->toContain("touch '/etc/caddy/Caddyfile.provision-previous-absent'")
        ->not->toContain("rm -f '/etc/caddy/Caddyfile.provision-previous'")
        ->and($executor->commands)->toHaveCount(5);
});
