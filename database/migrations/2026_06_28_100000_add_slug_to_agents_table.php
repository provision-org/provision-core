<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unlike `handle` (unique per team), `slug` is globally unique because it
     * becomes the agent's public subdomain ({slug}.provisionagents.com) where
     * published artifacts are served.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('handle');
        });

        // Backfill a globally-unique slug for existing agents, seeded from the
        // per-team handle (or a slugified name) and de-duplicated globally.
        $used = [];
        DB::table('agents')->orderBy('created_at')->orderBy('id')
            ->select('id', 'handle', 'name')
            ->each(function ($agent) use (&$used) {
                $base = $agent->handle ?: (Str::slug($agent->name) ?: 'agent');
                $slug = $base;
                $suffix = 2;
                while (isset($used[$slug])) {
                    $slug = "{$base}-{$suffix}";
                    $suffix++;
                }
                $used[$slug] = true;
                DB::table('agents')->where('id', $agent->id)->update(['slug' => $slug]);
            });

        Schema::table('agents', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
