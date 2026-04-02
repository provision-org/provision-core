<?php

use App\Models\AgentTemplate;
use Database\Seeders\AgentTemplateSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('seeder creates all templates', function () {
    $this->seed(AgentTemplateSeeder::class);

    expect(AgentTemplate::count())->toBe(17);
});

test('seeder is idempotent', function () {
    $this->seed(AgentTemplateSeeder::class);
    $this->seed(AgentTemplateSeeder::class);

    expect(AgentTemplate::count())->toBe(17);
});

test('all templates have required content', function () {
    $this->seed(AgentTemplateSeeder::class);

    $templates = AgentTemplate::all();

    foreach ($templates as $template) {
        expect($template->soul)->not->toBeEmpty("Template {$template->slug} has empty soul");
        expect($template->identity)->not->toBeEmpty("Template {$template->slug} has empty identity");
        expect($template->system_prompt)->not->toBeEmpty("Template {$template->slug} has empty system_prompt");
        expect($template->name)->not->toBeEmpty("Template {$template->slug} has empty name");
        expect($template->tagline)->not->toBeEmpty("Template {$template->slug} has empty tagline");
        expect($template->emoji)->not->toBeEmpty("Template {$template->slug} has empty emoji");
    }
});

test('all templates have valid roles', function () {
    $this->seed(AgentTemplateSeeder::class);

    $templates = AgentTemplate::all();

    foreach ($templates as $template) {
        expect($template->role)->not->toBeNull("Template {$template->slug} has null role");
    }
});

test('all templates are active by default', function () {
    $this->seed(AgentTemplateSeeder::class);

    expect(AgentTemplate::query()->where('is_active', false)->count())->toBe(0);
});
