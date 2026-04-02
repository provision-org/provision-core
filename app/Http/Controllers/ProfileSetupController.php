<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileSetupController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasCompletedProfile()) {
            return redirect()->route('teams.create');
        }

        return Inertia::render('profile-setup');
    }

    public function store(ProfileSetupRequest $request): RedirectResponse
    {
        $request->user()->update(array_merge(
            $request->validated(),
            ['profile_completed_at' => now()],
        ));

        return redirect()->route('teams.create');
    }
}
