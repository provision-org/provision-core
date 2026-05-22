<?php

use App\Jobs\SetupOpenClawOnServerJob;

test('parses lowercase v-prefix format (pre-5.6 OpenClaw)', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('openclaw v2026.3.8'))
        ->toBe('2026.3.8');
});

test('parses lowercase no-prefix format with build hash (5.6+)', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('openclaw 2026.5.6 (c97b9f7)'))
        ->toBe('2026.5.6');
});

test('parses 5.19 format from the actual openclaw binary', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('openclaw 2026.5.19 (abc1234)'))
        ->toBe('2026.5.19');
});

test('parses capital O variant (OpenClaw 2026.5.19)', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('OpenClaw 2026.5.19'))
        ->toBe('2026.5.19');
});

test('parses bare version string with leading v', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('v2026.5.19'))
        ->toBe('2026.5.19');
});

test('parses bare version string without leading v', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('2026.5.19'))
        ->toBe('2026.5.19');
});

test('parses prerelease version (2026.5.20-beta.1)', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion('openclaw 2026.5.20-beta.1'))
        ->toBe('2026.5.20-beta.1');
});

test('returns null for unparseable output', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion(''))->toBeNull()
        ->and(SetupOpenClawOnServerJob::parseOpenClawVersion('command not found'))->toBeNull()
        ->and(SetupOpenClawOnServerJob::parseOpenClawVersion('openclaw'))->toBeNull();
});

test('extracts version when wrapped in noise', function () {
    expect(SetupOpenClawOnServerJob::parseOpenClawVersion("Warning: foo\nopenclaw 2026.5.19 (abc)"))
        ->toBe('2026.5.19');
});
