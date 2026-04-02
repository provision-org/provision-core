<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ActivateUserCommand extends Command
{
    protected $signature = 'user:activate {email}';

    protected $description = 'Activate a waitlisted user by email';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        if ($user->isActivated()) {
            $this->info("User '{$email}' is already activated.");

            return self::SUCCESS;
        }

        $user->activate();

        $this->info("User '{$email}' has been activated.");

        return self::SUCCESS;
    }
}
