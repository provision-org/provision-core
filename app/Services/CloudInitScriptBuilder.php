<?php

namespace App\Services;

use App\Enums\HarnessType;

class CloudInitScriptBuilder
{
    public function build(string $callbackUrl, ?string $devicePath, string $timezone = 'UTC', HarnessType $harnessType = HarnessType::Hermes): string
    {
        $installHarness = match ($harnessType) {
            HarnessType::OpenClaw => $this->openClawInstall(),
            HarnessType::Hermes => $this->hermesInstall(),
        };

        $openClawDataPath = $devicePath ? '/mnt/openclaw-data' : '/srv/provision/openclaw-data';

        $storageSetup = $devicePath
            ? <<<BASH
            # Mount persistent volume
            ping_progress "mounting_volume"
            mkdir -p /mnt/openclaw-data
            for i in \$(seq 1 12); do [ -e {$devicePath} ] && break; sleep 5; done
            blkid {$devicePath} || mkfs.ext4 -F {$devicePath}
            mount {$devicePath} /mnt/openclaw-data
            echo "{$devicePath} /mnt/openclaw-data ext4 discard,nofail,defaults 0 0" >> /etc/fstab
            mkdir -p /mnt/openclaw-data/agents /mnt/openclaw-data/logs
            BASH
            : <<<'BASH'
            # Prepare snapshot-backed local storage
            ping_progress "mounting_volume"
            mkdir -p /srv/provision/openclaw-data/agents /srv/provision/openclaw-data/logs
            mkdir -p /srv/provision/shared /mnt
            install -d -m 0755 /etc/tmpfiles.d
            printf '%s\n' \
              'L+ /mnt/openclaw-data - - - - /srv/provision/openclaw-data' \
              'L+ /mnt/provision-shared - - - - /srv/provision/shared' \
              > /etc/tmpfiles.d/provision-storage.conf
            systemd-tmpfiles --create /etc/tmpfiles.d/provision-storage.conf
            BASH;

        return <<<BASH
        #!/bin/bash
        set -e
        export HOME=/root

        ping_progress() {
            curl -s -X POST "{$callbackUrl}&status=progress&step=\$1" || true
        }

        # Set timezone
        timedatectl set-timezone {$timezone}

        {$storageSetup}

        # Point apt at the canonical Ubuntu mirror instead of the regional EC2
        # mirror (*.ec2.archive.ubuntu.com). That mirror has intermittently
        # stalled bulk downloads — small requests succeed, large package fetches
        # hang — which silently drags a provision past its callback/timeout
        # windows and strands the server in `error`. archive.ubuntu.com is
        # reliable from EC2 (measured ~80MB/s vs the EC2 mirror timing out).
        # No-op on non-AWS providers, whose sources don't use that host.
        sed -i -E 's#https?://[a-z0-9-]+\\.ec2\\.archive\\.ubuntu\\.com/ubuntu#http://archive.ubuntu.com/ubuntu#g' \
          /etc/apt/sources.list /etc/apt/sources.list.d/*.sources 2>/dev/null || true
        # Survive transient mirror hiccups instead of failing the whole run.
        printf 'Acquire::Retries "5";\\nAcquire::http::Timeout "30";\\nAcquire::https::Timeout "30";\\n' \
          > /etc/apt/apt.conf.d/80-provision-retries

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
        wget -q -O - https://dl.google.com/linux/linux_signing_key.pub \
          | gpg --dearmor --batch --yes -o /usr/share/keyrings/google-chrome.gpg
        echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list
        apt-get update -y
        apt-get install -y google-chrome-stable

        # Install display and VNC packages for browser sharing
        ping_progress "installing_vnc"
        apt-get install -y --no-install-recommends xvfb x11vnc novnc python3-websockify

        # Install image-processing runtimes used by agent screenshot/canvas
        # tools (sharp/libvips, node-canvas backends). Without these the
        # browser plugin's screenshot encoding fails with "missing image library".
        ping_progress "installing_image_libs"
        apt-get install -y --no-install-recommends \
          libvips42 \
          libcairo2 \
          libpango-1.0-0 \
          libjpeg-turbo8 \
          libgif7 \
          librsvg2-2

        # Install Caddy (reverse proxy with automatic Let's Encrypt TLS)
        ping_progress "installing_caddy"
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
          | gpg --dearmor --batch --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
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
        ln -sfn {$openClawDataPath}/agents /root/.openclaw/agents
        ln -sfn {$openClawDataPath}/logs /root/.openclaw/logs

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

    public function buildForRootFilesystem(
        string $callbackUrl,
        string $timezone = 'UTC',
        HarnessType $harnessType = HarnessType::Hermes,
    ): string {
        return $this->build($callbackUrl, null, $timezone, $harnessType);
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

        # The installer may place OpenClaw and Node under root's NVM directory
        # without updating this non-login shell. Load NVM for the rest of setup,
        # then expose stable paths for SSH commands and systemd services.
        export NVM_DIR=/root/.nvm
        [ ! -s "\$NVM_DIR/nvm.sh" ] || . "\$NVM_DIR/nvm.sh"
        NVM_OPENCLAW_BIN=\$(find "\$NVM_DIR/versions/node" -path '*/bin/openclaw' -print -quit 2>/dev/null || true)
        if [ -n "\$NVM_OPENCLAW_BIN" ]; then
            NVM_BIN_DIR=\$(dirname "\$NVM_OPENCLAW_BIN")
            for executable in node npm npx corepack openclaw; do
                [ ! -e "\$NVM_BIN_DIR/\$executable" ] \
                  || ln -sfn "\$NVM_BIN_DIR/\$executable" "/usr/local/bin/\$executable"
            done
        fi
        hash -r
        command -v openclaw || { echo "OpenClaw install failed"; exit 1; }

        # OpenClaw's browser:screenshot tool requires `sharp` for image attachment
        # processing. Without it the agent gets "Optional dependency sharp is required"
        # and never delivers the screenshot bytes.
        OPENCLAW_PACKAGE_DIR="\$(npm root -g)/openclaw"
        (cd "\$OPENCLAW_PACKAGE_DIR" && npm install sharp --no-save) || true

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
