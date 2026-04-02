<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Services\ModuleRegistry;
use App\Support\Provision;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->load('currentTeam'),
            ],
            'teams' => fn () => $request->user()?->teams()->get() ?? [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'wallet' => function () use ($request) {
                $team = $request->user()?->currentTeam;

                if (! $team) {
                    return null;
                }

                $billingModel = Provision::teamModel();
                if ($billingModel !== Team::class && ! $team instanceof $billingModel) {
                    $team = $billingModel::find($team->id);
                }

                if ($team && method_exists($team, 'creditWallet')) {
                    return $team->creditWallet?->only([
                        'balance_cents',
                        'lifetime_credits_cents',
                        'lifetime_usage_cents',
                        'auto_topup_enabled',
                    ]);
                }

                return null;
            },
            'flash' => fn () => [
                'newToken' => $request->session()->get('newToken'),
            ],
            'modules' => fn () => app(ModuleRegistry::class)->sharedProps($request),
            'googleAuthEnabled' => (bool) config('services.google.client_id'),
        ];
    }
}
