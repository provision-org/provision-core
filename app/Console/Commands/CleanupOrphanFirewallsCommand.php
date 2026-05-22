<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * One-shot cleanup for DigitalOcean firewalls that were created by Provision
 * but were never released because the destroy path didn't release them
 * (issue #37). Run once after deploying the fix to clean up historical
 * orphans; new firewalls are released by DestroyTeamJob going forward.
 *
 * Identifies orphans as: droplet_ids == [] AND name starts with one of the
 * configured prefixes. Defaults to "provision-" and "warm-" — the two
 * patterns this codebase has historically produced.
 */
class CleanupOrphanFirewallsCommand extends Command
{
    protected $signature = 'cloud:cleanup-orphan-firewalls
        {--provider=digitalocean : Cloud provider (only digitalocean supported today)}
        {--prefix=provision-,warm- : Comma-separated firewall name prefixes to consider}
        {--dry-run : List orphans without deleting}';

    protected $description = 'Delete cloud firewalls that have no attached droplets (cleanup for #37 leak).';

    public function handle(HttpFactory $http): int
    {
        if ($this->option('provider') !== 'digitalocean') {
            $this->error('Only --provider=digitalocean is supported.');

            return self::FAILURE;
        }

        $token = config('cloud.digitalocean.api_token');
        if (! $token) {
            $this->error('cloud.digitalocean.api_token is not configured.');

            return self::FAILURE;
        }

        $prefixes = array_filter(array_map('trim', explode(',', (string) $this->option('prefix'))));
        if (empty($prefixes)) {
            $this->error('At least one --prefix is required.');

            return self::FAILURE;
        }

        $client = $http->withToken($token)->timeout(15)->baseUrl('https://api.digitalocean.com/v2');

        $response = $client->get('/firewalls', ['per_page' => 200])->throw();
        $all = $response->json('firewalls') ?? [];

        $orphans = array_values(array_filter($all, function (array $fw) use ($prefixes): bool {
            if (! empty($fw['droplet_ids'])) {
                return false;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) ($fw['name'] ?? ''), $prefix)) {
                    return true;
                }
            }

            return false;
        }));

        $this->info("Found {$response->json('meta.total')} firewalls total; ".count($orphans).' orphans match the prefixes.');

        if (empty($orphans)) {
            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($orphans as $fw) {
            $line = "  {$fw['id']}  {$fw['name']}";
            if ($this->option('dry-run')) {
                $this->line("[dry-run] {$line}");

                continue;
            }

            $delResponse = $client->delete("/firewalls/{$fw['id']}");
            if ($delResponse->status() === 204 || $delResponse->status() === 404) {
                $deleted++;
                $this->line("[deleted {$delResponse->status()}] {$line}");
            } else {
                $failed++;
                $this->error("[failed {$delResponse->status()}] {$line}");
            }
        }

        $this->newLine();
        if ($this->option('dry-run')) {
            $this->info(count($orphans).' orphans would be deleted. Re-run without --dry-run to actually delete.');
        } else {
            $this->info("Deleted {$deleted} orphans, {$failed} failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
