<?php

namespace App\Support;

use App\Contracts\CommandExecutor;
use App\Models\Server;
use InvalidArgumentException;
use Throwable;

final class OpenClawGatewayEndpoint
{
    public const CADDYFILE = '/etc/caddy/Caddyfile';

    /**
     * Serialize every Provision-owned mutation of the shared Caddy config.
     */
    public const CADDY_LOCK_FILE = '/run/lock/provision-caddy.lock';

    public const GATEWAY_PORT = 18789;

    /**
     * Return the public TLS hostname used only for OpenClaw WebSocket traffic.
     */
    public static function hostname(Server $server): string
    {
        return 'gateway.'.self::dashedIp($server).'.sslip.io';
    }

    /**
     * Return the public WebSocket URL encoded into an OpenClaw setup code.
     */
    public static function wssUrl(Server $server): string
    {
        return 'wss://'.self::hostname($server);
    }

    /**
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        return ['127.0.0.1', '::1'];
    }

    /**
     * @return list<string>
     */
    public static function allowedOrigins(Server $server): array
    {
        return ['https://'.self::hostname($server)];
    }

    /**
     * Build the complete Caddy config for browser sharing and the mobile gateway.
     *
     * The OpenClaw gateway remains bound to loopback. Caddy accepts only WebSocket
     * upgrade requests on the dedicated hostname and rejects ordinary HTTP.
     */
    public static function caddyfile(Server $server, bool $includeGateway = true): string
    {
        $browserHostname = self::dashedIp($server).'.sslip.io';
        $askUrl = rtrim((string) config('app.url'), '/').'/api/caddy/ask';

        $caddyfile = <<<CADDY
{
    on_demand_tls {
        ask {$askUrl}
    }
}

import /etc/caddy/sites/*.caddy

{$browserHostname} {
    import /etc/caddy/conf.d/*.caddy
}
CADDY;

        if (! $includeGateway) {
            return $caddyfile;
        }

        $gatewayHostname = self::hostname($server);

        return $caddyfile."\n\n".<<<CADDY
{$gatewayHostname} {
    @gateway_websocket `header({'Connection':'*Upgrade*','Upgrade':'websocket'}) || header({':protocol':'websocket'})`

    handle @gateway_websocket {
        reverse_proxy 127.0.0.1:18789 {
            header_up X-Forwarded-For {http.request.remote.host}
            header_up X-Forwarded-Proto https
            header_up -Forwarded
            header_up -X-Real-IP
        }
    }

    handle {
        respond 404
    }
}
CADDY;
    }

    /**
     * Idempotently install and reload the gateway proxy on an existing server.
     */
    public static function ensureConfigured(Server $server, CommandExecutor $executor): void
    {
        // Configure proxy awareness before Caddy makes the loopback Gateway
        // reachable. Otherwise every remote phone appears to be a local client.
        $trustedProxies = escapeshellarg(json_encode(self::trustedProxies(), JSON_THROW_ON_ERROR));
        $executor->exec("openclaw config set gateway.trustedProxies {$trustedProxies} --strict-json");
        $executor->exec('mkdir -p /etc/caddy/conf.d /etc/caddy/sites');

        $desired = self::caddyfile($server);
        $candidate = self::CADDYFILE.'.provision-mobile-'.bin2hex(random_bytes(8));
        $quotedCandidate = escapeshellarg($candidate);

        try {
            $executor->writeFile($candidate, $desired."\n");
            $executor->exec("chmod 0644 {$quotedCandidate}");
            $executor->exec("caddy validate --config {$quotedCandidate} --adapter caddyfile");
        } catch (Throwable $exception) {
            self::removeFiles($executor, $candidate);
            throw $exception;
        }

        // Do not clean the candidate or retained recovery state when this call
        // fails. An SSH exception can be ambiguous while the remote transaction
        // is still running, and deleting either could make recovery impossible.
        $executor->exec(self::replacementTransaction(self::CADDYFILE, $candidate));

        self::removeFiles($executor, $candidate);
    }

    /**
     * Build one locked remote transaction that swaps a validated candidate in,
     * validates the complete root config, reloads Caddy, and rolls back on any
     * failure. The prior file (or absence marker) is deliberately retained.
     */
    public static function replacementTransaction(string $activePath, string $candidatePath): string
    {
        $active = escapeshellarg($activePath);
        $candidate = escapeshellarg($candidatePath);
        $backup = escapeshellarg(self::backupPath($activePath));
        $absentMarker = escapeshellarg(self::absentMarkerPath($activePath));
        $lock = escapeshellarg(self::CADDY_LOCK_FILE);
        $root = escapeshellarg(self::CADDYFILE);

        return implode("\n", [
            '(',
            "    exec 9>{$lock} || exit 1",
            '    flock -x 9 || exit 1',
            '',
            "    if [ -f {$active} ]; then",
            "        rm -f {$absentMarker} || exit 1",
            "        cp -p {$active} {$backup} || exit 1",
            '    else',
            "        touch {$absentMarker} || exit 1",
            '    fi',
            '',
            "    if mv {$candidate} {$active} &&",
            "        chmod 0644 {$active} &&",
            "        caddy validate --config {$root} --adapter caddyfile &&",
            '        systemctl reload caddy; then',
            '        exit 0',
            '    fi',
            '',
            ...self::rollbackLines($active, $backup, $absentMarker, $root),
            '    exit 1',
            ')',
        ]);
    }

    /**
     * Build one locked remote transaction that removes an active site and
     * restores it if root validation or reload fails.
     */
    public static function removalTransaction(string $activePath): string
    {
        $active = escapeshellarg($activePath);
        $backup = escapeshellarg(self::backupPath($activePath));
        $absentMarker = escapeshellarg(self::absentMarkerPath($activePath));
        $lock = escapeshellarg(self::CADDY_LOCK_FILE);
        $root = escapeshellarg(self::CADDYFILE);

        return implode("\n", [
            '(',
            "    exec 9>{$lock} || exit 1",
            '    flock -x 9 || exit 1',
            '',
            "    if [ -f {$active} ]; then",
            "        rm -f {$absentMarker} || exit 1",
            "        cp -p {$active} {$backup} || exit 1",
            '    else',
            "        touch {$absentMarker} || exit 1",
            '    fi',
            '',
            "    if rm -f {$active} &&",
            "        caddy validate --config {$root} --adapter caddyfile &&",
            '        systemctl reload caddy; then',
            '        exit 0',
            '    fi',
            '',
            ...self::rollbackLines($active, $backup, $absentMarker, $root),
            '    exit 1',
            ')',
        ]);
    }

    private static function removeFiles(CommandExecutor $executor, string ...$paths): void
    {
        $quotedPaths = array_map(escapeshellarg(...), $paths);

        try {
            $executor->exec('rm -f '.implode(' ', $quotedPaths));
        } catch (Throwable) {
            // Cleanup must not hide the configuration or reload result.
        }
    }

    /**
     * @return list<string>
     */
    private static function rollbackLines(
        string $active,
        string $backup,
        string $absentMarker,
        string $root,
    ): array {
        return [
            "    if [ -f {$absentMarker} ]; then",
            "        rm -f {$active} || exit 1",
            "    elif [ -f {$backup} ]; then",
            "        cp -p {$backup} {$active} || exit 1",
            "        chmod 0644 {$active} || exit 1",
            '    else',
            '        exit 1',
            '    fi',
            "    caddy validate --config {$root} --adapter caddyfile || exit 1",
            '    systemctl reload caddy || exit 1',
        ];
    }

    private static function backupPath(string $activePath): string
    {
        return $activePath.'.provision-previous';
    }

    private static function absentMarkerPath(string $activePath): string
    {
        return $activePath.'.provision-previous-absent';
    }

    private static function dashedIp(Server $server): string
    {
        $ipAddress = $server->ipv4_address;

        if (! is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('OpenClaw gateway endpoints require a valid server IPv4 address.');
        }

        return str_replace('.', '-', $ipAddress);
    }
}
