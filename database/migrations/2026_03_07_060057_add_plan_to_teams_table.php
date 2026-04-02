<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('plan')->nullable()->after('onboarding_completed_at');
        });

        // Set existing subscribed teams to 'pro' (only when billing module is installed)
        if (Schema::hasTable('subscriptions')) {
            DB::table('teams')
                ->whereIn('id', DB::table('subscriptions')
                    ->where('type', 'default')
                    ->whereIn('stripe_status', ['active', 'trialing'])
                    ->pluck('team_id'))
                ->update(['plan' => 'pro']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
