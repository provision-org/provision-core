<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('redirects incomplete user to profile-setup', function () {
    if (! Route::has('subscribe')) {
        $this->markTestSkipped('Subscribe route requires the billing module');
    }

    $user = User::factory()->create(['profile_completed_at' => null]);

    $response = $this->actingAs($user)->get(route('subscribe'));

    $response->assertRedirect(route('profile-setup'));
});

test('passes through completed user', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertOk();
});

test('profile-setup route accessible without completed profile', function () {
    $user = User::factory()->create(['profile_completed_at' => null]);

    $response = $this->actingAs($user)->get(route('profile-setup'));

    $response->assertOk();
});
