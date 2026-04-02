<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Contracts\Modules\BillingProvider;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $hasBilling = app()->bound(BillingProvider::class);

        // In OSS mode (no billing), auto-verify and activate users since most
        // self-hosted instances don't have SMTP configured. In hosted mode (billing
        // installed), require email verification and waitlist activation.
        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'profile_completed_at' => now(),
            'activated_at' => $hasBilling ? null : now(),
            'email_verified_at' => $hasBilling ? null : now(),
        ]);

        Mail::to($user->email)->send(new WelcomeMail($user));

        return $user;
    }
}
