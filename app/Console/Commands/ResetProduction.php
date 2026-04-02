<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\DigitalOceanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Provision\MailboxKit\Services\MailboxKitService;

class ResetProduction extends Command
{
    protected $signature = 'app:reset-production {--keep-users : Keep user accounts but reset everything else}';

    protected $description = 'Delete all teams, agents, servers, subscriptions, and cloud resources for a clean test';

    public function handle(MailboxKitService $mailboxKitService): int
    {
        try {
            // 1. Clean up MailboxKit inboxes/webhooks for all agents
            $agents = Agent::with('emailConnection')->get();
            foreach ($agents as $agent) {
                $ec = $agent->emailConnection;
                if ($ec?->mailboxkit_inbox_id) {
                    try {
                        $mailboxKitService->deleteInbox($ec->mailboxkit_inbox_id);
                        $this->info("  Deleted MailboxKit inbox {$ec->mailboxkit_inbox_id} for agent {$agent->name}");
                    } catch (\Throwable $e) {
                        $this->warn("  Failed to delete MailboxKit inbox: {$e->getMessage()}");
                    }

                    if ($ec->mailboxkit_webhook_id) {
                        try {
                            $mailboxKitService->deleteWebhook($ec->mailboxkit_webhook_id);
                            $this->info("  Deleted MailboxKit webhook {$ec->mailboxkit_webhook_id}");
                        } catch (\Throwable $e) {
                            $this->warn("  Failed to delete MailboxKit webhook: {$e->getMessage()}");
                        }
                    }
                }
            }

            // 2. Clean up cloud servers and volumes
            $servers = Server::all();
            $do = new DigitalOceanService;

            foreach ($servers as $server) {
                $this->info("Server {$server->id} | provider_server_id: {$server->provider_server_id} | provider_volume_id: {$server->provider_volume_id}");

                if ($server->provider_server_id) {
                    try {
                        $do->deleteDroplet($server->provider_server_id);
                        $this->info("  Deleted DO droplet {$server->provider_server_id}");
                    } catch (\Throwable $e) {
                        $this->warn("  Failed to delete droplet: {$e->getMessage()}");
                    }
                }

                if ($server->provider_volume_id) {
                    try {
                        $do->deleteVolume($server->provider_volume_id);
                        $this->info("  Deleted DO volume {$server->provider_volume_id}");
                    } catch (\Throwable $e) {
                        $this->warn("  Failed to delete volume: {$e->getMessage()}");
                    }
                }
            }

            // 3. Cancel Stripe subscriptions via API
            $subscriptions = Subscription::all();
            $stripe = new \Stripe\StripeClient(config('cashier.secret'));
            foreach ($subscriptions as $sub) {
                if ($sub->stripe_id) {
                    $this->info("Canceling Stripe subscription {$sub->stripe_id}");
                    try {
                        $stripe->subscriptions->cancel($sub->stripe_id);
                    } catch (\Throwable $e) {
                        $this->warn("  Failed to cancel Stripe sub: {$e->getMessage()}");
                    }
                }
            }

            // 4. Delete all DB records (order matters for FK constraints)
            $tables = [
                'agent_activities',
                'agent_daily_stats',
                'agent_slack_connections',
                'agent_telegram_connections',
                'agent_discord_connections',
                'agent_email_connections',
                'agents',
                'server_events',
                'servers',
                'subscription_items',
                'subscriptions',
                'team_env_vars',
                'team_invitations',
                'team_user',
            ];

            foreach ($tables as $table) {
                try {
                    DB::table($table)->delete();
                    $this->info("  Cleared {$table}");
                } catch (\Throwable $e) {
                    $this->warn("  Skipped {$table}: {$e->getMessage()}");
                }
            }

            // Reset user team references
            DB::table('users')->update(['current_team_id' => null]);

            // Delete teams
            DB::table('teams')->delete();

            $this->info('All teams, agents, servers, subscriptions, and cloud resources deleted.');
            $this->info('User accounts preserved with current_team_id reset to null.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Exception: {$e->getMessage()}");
            $this->error("File: {$e->getFile()}:{$e->getLine()}");

            return self::FAILURE;
        }
    }
}
