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
            $table->string('slug', 63)->nullable()->after('handle');
            $table->string('artifact_dns_record_id')->nullable()->after('slug');
            $table->string('artifact_dns_record_name')->nullable()->after('artifact_dns_record_id');
            $table->string('artifact_dns_zone_id')->nullable()->after('artifact_dns_record_name');
            $table->boolean('artifact_cleanup_required')->default(false)->after('artifact_dns_zone_id');
        });

        // Include a stable ID suffix so deleting an agent never makes its public
        // hostname available to a future tenant.
        DB::table('agents')->orderBy('created_at')->orderBy('id')
            ->select('id', 'handle', 'name')
            ->each(function ($agent) {
                $suffix = Str::lower((string) $agent->id);
                $base = Str::limit(
                    Str::slug($agent->handle ?: $agent->name) ?: 'agent',
                    62 - strlen($suffix),
                    '',
                );
                $slug = "{$base}-{$suffix}";
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
            $table->dropColumn([
                'slug',
                'artifact_dns_record_id',
                'artifact_dns_record_name',
                'artifact_dns_zone_id',
                'artifact_cleanup_required',
            ]);
        });
    }
};
