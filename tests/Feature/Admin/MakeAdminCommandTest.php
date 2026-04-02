<?php

use App\Models\User;

test('make-admin command grants admin privileges', function () {
    $user = User::factory()->create(['email' => 'newadmin@example.com']);

    $this->artisan('user:make-admin', ['email' => 'newadmin@example.com'])
        ->expectsOutput("User 'newadmin@example.com' has been granted admin privileges.")
        ->assertSuccessful();

    $user->refresh();
    expect($user->isAdmin())->toBeTrue();
});

test('make-admin command reports already admin user', function () {
    User::factory()->admin()->create(['email' => 'admin@example.com']);

    $this->artisan('user:make-admin', ['email' => 'admin@example.com'])
        ->expectsOutput("User 'admin@example.com' is already an admin.")
        ->assertSuccessful();
});

test('make-admin command fails for non-existent user', function () {
    $this->artisan('user:make-admin', ['email' => 'nobody@example.com'])
        ->expectsOutput("User with email 'nobody@example.com' not found.")
        ->assertFailed();
});
