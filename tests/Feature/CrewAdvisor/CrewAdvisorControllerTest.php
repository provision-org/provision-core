<?php

use App\Ai\Agents\CrewAdvisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! file_exists(app_path('Ai/Agents/CrewAdvisor.php'))) {
        $this->markTestSkipped('CrewAdvisor class not available');
    }
});

function advisorUser(): User
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $team->forceFill([
        'company_name' => 'Acme Corp',
        'company_description' => 'We build widgets.',
        'company_url' => 'https://acme.com',
        'target_market' => 'Small businesses',
    ])->save();

    subscribeTeam($team, 'pro');

    return $user;
}

test('crew advisor show page renders successfully', function () {
    $user = advisorUser();

    $response = $this->actingAs($user)->get(route('crew-advisor'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/crew-advisor')
        ->has('companyName')
        ->has('hasCompanyContext')
    );
});

test('crew advisor show page includes company name', function () {
    $user = advisorUser();

    $response = $this->actingAs($user)->get(route('crew-advisor'));

    $response->assertInertia(fn ($page) => $page
        ->where('companyName', 'Acme Corp')
        ->where('hasCompanyContext', true)
    );
});

test('crew advisor chat requires authentication', function () {
    $response = $this->postJson(route('crew-advisor.chat'), [
        'message' => 'Hello',
    ]);

    $response->assertUnauthorized();
});

test('crew advisor chat validates message is required', function () {
    $user = advisorUser();

    $response = $this->actingAs($user)->postJson(route('crew-advisor.chat'), []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('message');
});

test('crew advisor chat validates message max length', function () {
    $user = advisorUser();

    $response = $this->actingAs($user)->postJson(route('crew-advisor.chat'), [
        'message' => str_repeat('a', 2001),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('message');
});

test('crew advisor chat validates history format', function () {
    $user = advisorUser();

    $response = $this->actingAs($user)->postJson(route('crew-advisor.chat'), [
        'message' => 'Hello',
        'history' => [
            ['role' => 'invalid_role', 'content' => 'test'],
        ],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('history.0.role');
});

test('crew advisor chat streams a response', function () {
    CrewAdvisor::fake(['Hello! I can see you run Acme Corp.']);

    $user = advisorUser();

    $response = $this->actingAs($user)->postJson(route('crew-advisor.chat'), [
        'message' => 'Help me build my crew',
    ]);

    $response->assertSuccessful();

    CrewAdvisor::assertPrompted('Help me build my crew');
});

test('crew advisor chat accepts conversation history', function () {
    CrewAdvisor::fake(['Here are my recommendations.']);

    $user = advisorUser();

    $response = $this->actingAs($user)->postJson(route('crew-advisor.chat'), [
        'message' => 'What agents do you recommend?',
        'history' => [
            ['role' => 'user', 'content' => 'We sell SaaS tools'],
            ['role' => 'assistant', 'content' => 'Got it! Tell me more about your team.'],
        ],
    ]);

    $response->assertSuccessful();

    CrewAdvisor::assertPrompted('What agents do you recommend?');
});
