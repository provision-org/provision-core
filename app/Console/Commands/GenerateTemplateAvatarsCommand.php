<?php

namespace App\Console\Commands;

use App\Models\AgentTemplate;
use App\Services\ReplicateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GenerateTemplateAvatarsCommand extends Command
{
    protected $signature = 'templates:generate-avatars {--force : Regenerate all avatars, even existing ones}';

    protected $description = 'Generate abstract geometric avatars for all agent templates';

    public function handle(ReplicateService $replicate): int
    {
        if (! config('replicate.api_token')) {
            $this->error('REPLICATE_API_TOKEN is not configured.');

            return self::FAILURE;
        }

        $templates = AgentTemplate::query()->active()->get();
        $force = $this->option('force');

        $this->info("Generating avatars for {$templates->count()} templates...");

        foreach ($templates as $template) {
            if ($template->avatar_path && ! $force) {
                $this->line("  Skipping {$template->name} (already has avatar)");

                continue;
            }

            if (! $template->role) {
                $this->warn("  Skipping {$template->name} (no role set)");

                continue;
            }

            $this->line("  Generating avatar for {$template->name}...");

            try {
                $imageUrl = $replicate->generateAvatar($template->role->avatarPrompt());
                $imageContents = Http::timeout(30)->get($imageUrl)->body();

                $path = "avatars/templates/{$template->slug}.jpg";
                Storage::disk('public')->put($path, $imageContents);

                $template->update(['avatar_path' => $path]);

                $this->info("  ✓ {$template->name}");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$template->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('Done!');

        return self::SUCCESS;
    }
}
