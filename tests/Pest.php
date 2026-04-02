<?php

use App\Contracts\Modules\AgentEmailProvider;
use App\Models\Team;
use App\Services\ModuleRegistry;
use App\Support\Provision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Provision\Billing\Enums\SubscriptionPlan;
use Provision\MailboxKit\MailboxKitModule;
use Provision\MailboxKit\Services\EmailProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Subscribe a team to a plan using the billing module's BillableTeam model.
 * This creates the necessary Stripe-like subscription records for tests.
 */
function subscribeTeam(Team $team, string $plan = 'starter'): void
{
    $billingModel = Provision::teamModel();
    $bt = ($billingModel !== Team::class)
        ? $billingModel::find($team->id)
        : $team;

    $planEnum = SubscriptionPlan::from($plan);
    $bt->forceFill(['plan' => $planEnum, 'stripe_id' => 'cus_test_'.uniqid()])->save();
    $bt->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_'.uniqid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);
}

/**
 * Register the MailboxKit email module in the container with a mocked MailboxKitService.
 *
 * Tests that exercise email provisioning must call this because the module is normally
 * registered during AppServiceProvider::boot() which runs before tests can set config values.
 *
 * When the MailboxKit module package is not installed, this is a no-op.
 */
function registerMailboxKitModule(MockInterface $mailboxKitMock): void
{
    if (! class_exists(MailboxKitModule::class)) {
        return;
    }

    config(['mailboxkit.api_key' => 'mbk-test-key']);

    app()->instance(MailboxKitService::class, $mailboxKitMock);

    $module = new MailboxKitModule(
        app(EmailProvisioningService::class),
        $mailboxKitMock,
    );

    $registry = app(ModuleRegistry::class);
    $registry->register($module);
    app()->instance(AgentEmailProvider::class, $module);
}
