<?php

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
