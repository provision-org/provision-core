<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;

class ResetUser extends Command
{
    protected $signature = 'app:reset-user {email}';

    protected $description = 'Delete teams, subscriptions, and Stripe data for a user';

    public function handle(): int
    {
        try {
            $email = $this->argument('email');
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error("User {$email} not found.");

                return self::FAILURE;
            }

            $this->info("User: {$user->id} | {$user->name} | {$user->email}");

            // Get all teams owned by this user
            $teams = $user->ownedTeams;

            foreach ($teams as $team) {
                $this->info("Team: {$team->id} | {$team->name} | plan: {$team->plan?->value}");

                // Cancel Stripe subscription
                $subscription = $team->subscription('default');
                if ($subscription) {
                    try {
                        $subscription->cancelNow();
                        $this->info('  Cancelled Stripe subscription');
                    } catch (\Exception $e) {
                        $this->warn("  Failed to cancel subscription: {$e->getMessage()}");
                    }
                }

                // Delete Stripe customer
                if ($team->hasStripeId()) {
                    try {
                        Cashier::stripe()->customers->delete($team->stripe_id);
                        $this->info("  Deleted Stripe customer {$team->stripe_id}");
                    } catch (\Exception $e) {
                        $this->warn("  Failed to delete Stripe customer: {$e->getMessage()}");
                    }
                }

                // Dispatch synchronous cleanup (external APIs + DB cascade)
                \App\Jobs\DestroyTeamJob::dispatchSync($team);
                $this->info("  Destroyed team {$team->id} (agents, server, keys, external resources)");
            }

            // Also delete personal teams
            $personalTeams = $user->teams()->where('personal_team', true)->get();
            foreach ($personalTeams as $team) {
                $team->members()->detach();
                $team->delete();
                $this->info("  Deleted personal team {$team->id}");
            }

            // Reset user's current team
            $user->update(['current_team_id' => null]);
            $this->info('Reset current_team_id to null');

            $this->info('Done. User can now create a fresh team and subscribe.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Exception: {$e->getMessage()}");
            $this->error("File: {$e->getFile()}:{$e->getLine()}");

            return self::FAILURE;
        }
    }
}
