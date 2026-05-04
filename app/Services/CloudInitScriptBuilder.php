<?php

namespace App\Services;

use App\Enums\HarnessType;

class CloudInitScriptBuilder
{
    public function build(string $callbackUrl, string $devicePath, string $timezone = 'UTC', HarnessType $harnessType = HarnessType::Hermes): string
    {
        $installHarness = match ($harnessType) {
            HarnessType::OpenClaw => $this->openClawInstall(),
            HarnessType::Hermes => $this->hermesInstall(),
        };

        return <<<BASH
        #!/bin/bash
        set -e
        export HOME=/root

        ping_progress() {
            curl -s -X POST "{$callbackUrl}&status=progress&step=\$1" || true
        }

        # Set timezone
        timedatectl set-timezone {$timezone}

        # Mount persistent volume
        ping_progress "mounting_volume"
        mkdir -p /mnt/openclaw-data
        for i in \$(seq 1 12); do [ -e {$devicePath} ] && break; sleep 5; done
        blkid {$devicePath} || mkfs.ext4 -F {$devicePath}
        mount {$devicePath} /mnt/openclaw-data
        echo "{$devicePath} /mnt/openclaw-data ext4 discard,nofail,defaults 0 0" >> /etc/fstab
        mkdir -p /mnt/openclaw-data/agents /mnt/openclaw-data/logs

        # Update and install system packages
        ping_progress "installing_packages"
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -y
        apt-get install -y --no-install-recommends curl wget git unzip jq ufw

        # Install GitHub CLI
        ping_progress "installing_github_cli"
        curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
          | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
        echo "deb [arch=amd64 signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] \
          https://cli.github.com/packages stable main" \
          > /etc/apt/sources.list.d/github-cli.list
        apt-get update -y
        apt-get install -y gh

        # Install Google Chrome (full desktop — avoids CAPTCHA/bot detection unlike headless Chromium)
        ping_progress "installing_chrome"
        wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg
        echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list
        apt-get update -y
        apt-get install -y google-chrome-stable

        # Install display and VNC packages for browser sharing
        ping_progress "installing_vnc"
        apt-get install -y --no-install-recommends xvfb x11vnc novnc python3-websockify

        # Install Caddy (reverse proxy with automatic Let's Encrypt TLS)
        ping_progress "installing_caddy"
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
        echo "deb [signed-by=/usr/share/keyrings/caddy-stable-archive-keyring.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main" > /etc/apt/sources.list.d/caddy-stable.list
        apt-get update -y
        apt-get install -y caddy

        # Install agent harness
        {$installHarness}

        # Install ByteRover CLI for persistent agent memory
        curl -fsSL https://byterover.dev/install.sh | sh || true

        # Symlink to persistent volume
        mkdir -p /root/.openclaw
        rm -rf /root/.openclaw/agents /root/.openclaw/logs
        ln -sfn /mnt/openclaw-data/agents /root/.openclaw/agents
        ln -sfn /mnt/openclaw-data/logs /root/.openclaw/logs

        # Configure firewall
        ping_progress "configuring_firewall"
        ufw default deny incoming
        ufw default allow outgoing
        ufw allow 22/tcp
        ufw allow 80/tcp   # ACME challenge for Let's Encrypt
        ufw allow 443/tcp  # Caddy HTTPS reverse proxy for noVNC
        ufw --force enable

        # Callback on success
        curl -s -X POST "{$callbackUrl}&status=ready" || true
        BASH;
    }

    private function openClawInstall(): string
    {
        $version = config('provision.openclaw_version');

        return <<<BASH
        ping_progress "installing_openclaw"
        # Version pinned via config('provision.openclaw_version'). 2026.5.3-1 is the
        # first release where `devices approve` has a local-pairing fallback over
        # loopback and the gateway no longer auto-clobbers config edits.
        export OPENCLAW_VERSION={$version}
        curl -fsSL https://openclaw.ai/install.sh | bash || true
        command -v openclaw || { echo "OpenClaw install failed"; exit 1; }

        # Install QMD memory backend for improved agent recall
        npm install -g qmd 2>/dev/null || true
        BASH;
    }

    private function hermesInstall(): string
    {
        return <<<'BASH'
        ping_progress "installing_hermes"
        curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash || true
        command -v /root/.local/bin/hermes || { echo "Hermes install failed"; exit 1; }
        BASH;
    }
}
