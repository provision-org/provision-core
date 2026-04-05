<?php

use App\Ai\CompanyExtractorAgent;
use App\Models\User;
use App\Services\FirecrawlService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns extracted data on success', function () {
    $firecrawlMock = Mockery::mock(FirecrawlService::class);
    $firecrawlMock->shouldReceive('scrape')
        ->once()
        ->with('https://example.com')
        ->andReturn([
            'markdown' => '# Example Company\nWe build great software.',
            'metadata' => [],
        ]);
    $this->app->instance(FirecrawlService::class, $firecrawlMock);

    $extractorMock = Mockery::mock(CompanyExtractorAgent::class);
    $extractorMock->shouldReceive('extract')
        ->once()
        ->andReturn([
            'company_name' => 'Example Co',
            'company_description' => 'We build great software.',
            'target_market' => 'Developers',
        ]);
    $this->app->instance(CompanyExtractorAgent::class, $extractorMock);

    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->postJson(route('teams.scrape-company'), [
        'url' => 'https://example.com',
    ]);

    $response->assertOk()
        ->assertJson([
            'company_name' => 'Example Co',
            'company_description' => 'We build great software.',
            'target_market' => 'Developers',
        ]);
});

test('returns 422 on firecrawl failure', function () {
    $firecrawlMock = Mockery::mock(FirecrawlService::class);
    $firecrawlMock->shouldReceive('scrape')
        ->once()
        ->andThrow(new RuntimeException('API error'));
    $this->app->instance(FirecrawlService::class, $firecrawlMock);

    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->postJson(route('teams.scrape-company'), [
        'url' => 'https://example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonStructure(['message']);
});

test('validates url is required', function () {
    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->postJson(route('teams.scrape-company'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['url']);
});

test('validates url format', function () {
    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->postJson(route('teams.scrape-company'), [
        'url' => 'not-a-url',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['url']);
});
