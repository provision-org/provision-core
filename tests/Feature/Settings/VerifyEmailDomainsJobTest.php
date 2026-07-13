<?php

use App\Jobs\VerifyEmailDomainsJob;
use App\Models\Team;
use App\Models\TeamEmailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
});

test('it flips an unverified domain to verified when DNS now checks out', function () {
    $team = Team::factory()->create();
    $domain = TeamEmailDomain::create([
        'team_id' => $team->id,
        'mailboxkit_domain_id' => 'mbk-1',
        'name' => 'acme.com',
        'is_verified' => false,
    ]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('verifyDomain')->once()->with('mbk-1')
        ->andReturn(['data' => [
            'is_verified' => true, 'mx_verified' => true, 'spf_verified' => true,
            'dkim_verified' => true, 'dmarc_verified' => true, 'verified_at' => '2026-07-13T00:00:00Z',
        ]]);
    app()->instance(MailboxKitService::class, $mock);

    (new VerifyEmailDomainsJob)->handle();

    expect($domain->fresh())
        ->is_verified->toBeTrue()
        ->mx_verified->toBeTrue()
        ->and($domain->fresh()->verified_at)->not->toBeNull();
});

test('it skips already-verified domains', function () {
    $team = Team::factory()->create();
    TeamEmailDomain::create([
        'team_id' => $team->id, 'mailboxkit_domain_id' => 'mbk-1', 'name' => 'acme.com', 'is_verified' => true,
    ]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('verifyDomain')->never();
    app()->instance(MailboxKitService::class, $mock);

    (new VerifyEmailDomainsJob)->handle();

    expect(TeamEmailDomain::where('name', 'acme.com')->first()->is_verified)->toBeTrue();
});

test('a MailboxKit failure only stamps last_checked_at and does not throw', function () {
    $team = Team::factory()->create();
    $domain = TeamEmailDomain::create([
        'team_id' => $team->id, 'mailboxkit_domain_id' => 'mbk-1', 'name' => 'acme.com', 'is_verified' => false,
    ]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('verifyDomain')->once()->andThrow(new RuntimeException('down'));
    app()->instance(MailboxKitService::class, $mock);

    (new VerifyEmailDomainsJob)->handle();

    expect($domain->fresh())
        ->is_verified->toBeFalse()
        ->and($domain->fresh()->last_checked_at)->not->toBeNull();
});
