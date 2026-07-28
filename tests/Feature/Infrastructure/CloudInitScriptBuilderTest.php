<?php

use App\Enums\HarnessType;
use App\Services\CloudInitScriptBuilder;

it('interpolates hetzner volume device path', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->build(
        'https://example.com/callback?server_id=abc',
        '/dev/disk/by-id/scsi-0HC_Volume_12345',
        'UTC',
    );

    expect($script)->toContain('/dev/disk/by-id/scsi-0HC_Volume_12345')
        ->and($script)->toContain('/mnt/openclaw-data')
        ->and($script)->toContain('ln -sfn /mnt/openclaw-data/agents /root/.openclaw/agents');
});

it('interpolates digitalocean volume device path', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->build(
        'https://example.com/callback?server_id=abc',
        '/dev/disk/by-id/scsi-0DO_Volume_provision-team1-server1',
        'UTC',
    );

    expect($script)->toContain('/dev/disk/by-id/scsi-0DO_Volume_provision-team1-server1');
});

it('includes callback url in the script', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->build(
        'https://example.com/api/webhooks/server-ready?server_id=abc&signature=xyz',
        '/dev/disk/by-id/scsi-0HC_Volume_99999',
        'UTC',
    );

    expect($script)->toContain('server-ready')
        ->and($script)->toContain('signature=xyz');
});

it('sets the timezone from parameter', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->build(
        'https://example.com/callback',
        '/dev/disk/by-id/scsi-0HC_Volume_1',
        'America/New_York',
    );

    expect($script)->toContain('timedatectl set-timezone America/New_York');
});

it('imports apt repository keyrings non-interactively', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->buildForRootFilesystem(
        'https://example.com/callback',
        'UTC',
    );

    expect($script)->toContain(
        'gpg --dearmor --batch --yes -o /usr/share/keyrings/google-chrome.gpg',
        'gpg --dearmor --batch --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg',
    );
});

it('publishes an nvm-installed openclaw runtime for non-login processes', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->buildForRootFilesystem(
        'https://example.com/callback',
        'UTC',
        HarnessType::OpenClaw,
    );

    expect($script)->toContain(
        'export NVM_DIR=/root/.nvm',
        '[ ! -s "$NVM_DIR/nvm.sh" ] || . "$NVM_DIR/nvm.sh"',
        'NVM_OPENCLAW_BIN=$(find "$NVM_DIR/versions/node" -path \'*/bin/openclaw\' -print -quit',
        'ln -sfn "$NVM_BIN_DIR/$executable" "/usr/local/bin/$executable"',
        'OPENCLAW_PACKAGE_DIR="$(npm root -g)/openclaw"',
    );
});

it('uses snapshot-backed root storage without formatting a device', function () {
    $builder = new CloudInitScriptBuilder;
    $script = $builder->buildForRootFilesystem(
        'https://example.com/callback',
        'UTC',
    );

    expect($script)->toContain('Prepare snapshot-backed local storage')
        ->and($script)->toContain('mkdir -p /srv/provision/openclaw-data/agents /srv/provision/openclaw-data/logs')
        ->and($script)->toContain('/etc/tmpfiles.d/provision-storage.conf')
        ->and($script)->toContain('L+ /mnt/openclaw-data - - - - /srv/provision/openclaw-data')
        ->and($script)->toContain('L+ /mnt/provision-shared - - - - /srv/provision/shared')
        ->and($script)->toContain('ln -sfn /srv/provision/openclaw-data/agents /root/.openclaw/agents')
        ->and($script)->toContain('ln -sfn /srv/provision/openclaw-data/logs /root/.openclaw/logs')
        ->and($script)->not->toContain('mkfs.ext4')
        ->and($script)->not->toContain('/etc/fstab');
});
