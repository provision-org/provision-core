<?php

namespace App\Support;

use App\Contracts\CommandExecutor;
use App\Models\Server;
use InvalidArgumentException;
use Throwable;

final class OpenClawGatewayEndpoint
{
    public const CADDYFILE = '/etc/caddy/Caddyfile';

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
        $caddyfile = escapeshellarg(self::CADDYFILE);
        $current = $executor->exec("if [ -f {$caddyfile} ]; then cat {$caddyfile}; fi");

        $candidate = self::CADDYFILE.'.provision-mobile-'.bin2hex(random_bytes(8));
        $backup = $candidate.'.backup';
        $quotedCandidate = escapeshellarg($candidate);
        $quotedBackup = escapeshellarg($backup);
        $replaced = false;

        try {
            $executor->writeFile($candidate, $desired."\n");
            $executor->exec("chmod 0644 {$quotedCandidate}");
            $executor->exec("caddy validate --config {$quotedCandidate} --adapter caddyfile");

            if (rtrim($current) === rtrim($desired)) {
                $executor->exec("chmod 0644 {$caddyfile}");
                $executor->exec('systemctl reload caddy');

                return;
            }

            $executor->exec("if [ -f {$caddyfile} ]; then cp -p {$caddyfile} {$quotedBackup}; fi");
            $executor->exec("mv {$quotedCandidate} {$caddyfile}");
            $replaced = true;
            $executor->exec("chmod 0644 {$caddyfile}");
            $executor->exec('systemctl reload caddy');
        } catch (Throwable $exception) {
            if ($replaced) {
                try {
                    $executor->exec("if [ -f {$quotedBackup} ]; then mv {$quotedBackup} {$caddyfile} && chmod 0644 {$caddyfile} && systemctl reload caddy; else rm -f {$caddyfile}; fi");
                } catch (Throwable) {
                    // Preserve the original configuration or reload failure.
                }
            }

            throw $exception;
        } finally {
            self::removeFiles($executor, $candidate, $backup);
        }
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

    private static function dashedIp(Server $server): string
    {
        $ipAddress = $server->ipv4_address;

        if (! is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('OpenClaw gateway endpoints require a valid server IPv4 address.');
        }

        return str_replace('.', '-', $ipAddress);
    }
}
