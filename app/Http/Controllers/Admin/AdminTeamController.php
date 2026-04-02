<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Provision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Provision\Billing\Enums\CreditTransactionType;

class AdminTeamController extends Controller
{
    public function index(): Response
    {
        $billingModel = Provision::teamModel();
        $query = $billingModel::query()
            ->with(['owner:id,name,email', 'server:id,team_id,status'])
            ->withCount(['members', 'agents'])
            ->latest();

        if (method_exists($billingModel, 'creditWallet')) {
            $query->with('creditWallet');
        }

        return Inertia::render('admin/teams/index', [
            'teams' => $query->paginate(25),
        ]);
    }

    public function grantCredits(Request $request, Team $team): RedirectResponse
    {
        $billingModel = Provision::teamModel();
        $bt = ($billingModel !== Team::class) ? ($billingModel::find($team->id) ?? $team) : $team;

        abort_unless(method_exists($bt, 'creditWallet'), 404, 'Billing module is not installed.');

        $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:10000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $amountCents = (int) ($request->input('amount') * 100);
        $reason = $request->input('reason', 'Admin credit grant');

        $wallet = $bt->creditWallet ?? $bt->creditWallet()->create([
            'balance_cents' => 0,
            'lifetime_credits_cents' => 0,
            'lifetime_usage_cents' => 0,
        ]);

        $creditTransactionType = CreditTransactionType::ManualAdjustment;
        $wallet->credit($amountCents, $creditTransactionType, $reason, [
            'granted_by' => $request->user()->id,
        ]);

        return back()->with('status', "Granted \${$request->input('amount')} to {$team->name}");
    }
}
