<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamEmailDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function mockMailboxKitDomainService(): MockInterface
{
    /** @var MockInterface $mock */
    $mock = Mockery::mock(MailboxKitService::class);
    registerMailboxKitModule($mock);

    return $mock;
}

beforeEach(function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
});

test('admin can view email-domain page when no domain registered', function () {
    mockMailboxKitDomainService();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->get(route('teams.email-domain.show', $team));

    $response->assertSuccessful();
    $response->assertInertia(
        fn ($page) => $page
            ->component('settings/teams/email-domain')
            ->where('domain', null)
            ->where('defaultDomain', config('mailboxkit.email_domain')),
    );
});

test('admin can register a subdomain', function () {
    $mock = mockMailboxKitDomainService();
    $mock->shouldReceive('createDomain')
        ->with('email.example.com')
        ->once()
        ->andReturn(['data' => ['id' => 42, 'name' => 'email.example.com']]);
    $mock->shouldReceive('getDomainDnsRecords')
        ->with(42)
        ->once()
        ->andReturn([
            'data' => [
                'mx' => ['type' => 'MX', 'host' => '@', 'value' => 'mx.mailboxkit.com', 'priority' => 10],
                'spf' => ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:mailboxkit.com -all'],
                'dkim' => ['type' => 'TXT', 'host' => 'default._domainkey', 'value' => 'v=DKIM1; k=rsa; p=...'],
                'dmarc' => ['type' => 'TXT', 'host' => '_dmarc', 'value' => 'v=DMARC1; p=none;'],
            ],
        ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(
        route('teams.email-domain.store', $team),
        ['name' => 'email.example.com'],
    );

    $response->assertRedirect();
    $domain = $team->fresh()->emailDomain;
    expect($domain)->not->toBeNull()
        ->and($domain->mailboxkit_domain_id)->toBe('42')
        ->and($domain->name)->toBe('email.example.com')
        ->and($domain->is_verified)->toBeFalse()
        ->and($domain->dns_records)->toHaveKeys(['mx', 'spf', 'dkim', 'dmarc']);
});

test('apex domain is rejected to protect customer mx', function () {
    mockMailboxKitDomainService();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(
        route('teams.email-domain.store', $team),
        ['name' => 'example.com'],
    );

    $response->assertSessionHasErrors(['name']);
    expect($team->fresh()->emailDomain)->toBeNull();
});

test('uppercase domain is rejected', function () {
    mockMailboxKitDomainService();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(
        route('teams.email-domain.store', $team),
        ['name' => 'Email.Example.com'],
    );

    $response->assertSessionHasErrors(['name']);
});

test('admin cannot register a second domain while one exists', function () {
    mockMailboxKitDomainService();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    TeamEmailDomain::create([
        'team_id' => $team->id,
        'mailboxkit_domain_id' => '1',
        'name' => 'email.first.com',
    ]);

    $response = $this->actingAs($user)->post(
        route('teams.email-domain.store', $team),
        ['name' => 'email.second.com'],
    );

    $response->assertSessionHasErrors(['name']);
});

test('admin can recheck verification', function () {
    $mock = mockMailboxKitDomainService();
    $mock->shouldReceive('verifyDomain')
        ->with('99')
        ->once()
        ->andReturn([
            'data' => [
                'id' => 99,
                'is_verified' => true,
                'mx_verified' => true,
                'spf_verified' => true,
                'dkim_verified' => true,
                'dmarc_verified' => true,
                'verified_at' => '2026-05-04T12:00:00Z',
            ],
        ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $domain = TeamEmailDomain::create([
        'team_id' => $team->id,
        'mailboxkit_domain_id' => '99',
        'name' => 'email.example.com',
    ]);

    $response = $this->actingAs($user)->post(
        route('teams.email-domain.verify', $team),
    );

    $response->assertRedirect();
    $domain->refresh();
    expect($domain->is_verified)->toBeTrue()
        ->and($domain->mx_verified)->toBeTrue()
        ->and($domain->dkim_verified)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull();
});

test('admin can remove domain', function () {
    $mock = mockMailboxKitDomainService();
    $mock->shouldReceive('deleteDomain')->with('77')->once();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    TeamEmailDomain::create([
        'team_id' => $team->id,
        'mailboxkit_domain_id' => '77',
        'name' => 'email.example.com',
    ]);

    $response = $this->actingAs($user)
        ->delete(route('teams.email-domain.destroy', $team));

    $response->assertRedirect();
    expect($team->fresh()->emailDomain)->toBeNull();
});

test('non-admin member cannot access', function () {
    mockMailboxKitDomainService();
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $response = $this->actingAs($member->fresh())
        ->get(route('teams.email-domain.show', $team));

    $response->assertForbidden();
});

test('user from another team cannot access', function () {
    mockMailboxKitDomainService();
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->subscribed()->create();

    $response = $this->actingAs($user)
        ->get(route('teams.email-domain.show', $foreignTeam));

    $response->assertForbidden();
});

test('activeEmailDomain falls back to platform default when no custom domain', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    expect($team->activeEmailDomain())->toBe(config('mailboxkit.email_domain'));
});

test('activeEmailDomain returns custom domain only when verified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $domain = TeamEmailDomain::create([
        'team_id' => $team->id,
        'mailboxkit_domain_id' => '1',
        'name' => 'email.example.com',
        'is_verified' => false,
    ]);

    expect($team->fresh()->activeEmailDomain())->toBe(config('mailboxkit.email_domain'));

    $domain->update(['is_verified' => true]);

    expect($team->fresh()->activeEmailDomain())->toBe('email.example.com');
});
