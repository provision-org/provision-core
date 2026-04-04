<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('agent_mode')->default('channel')->after('status');
            $table->foreignUlid('reports_to')->nullable()->after('agent_mode')
                ->constrained('agents')->nullOnDelete();
            $table->string('org_title', 100)->nullable()->after('reports_to');
            $table->text('capabilities')->nullable()->after('org_title');
            $table->boolean('delegation_enabled')->default(false)->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['reports_to']);
            $table->dropColumn([
                'agent_mode',
                'reports_to',
                'org_title',
                'capabilities',
                'delegation_enabled',
            ]);
        });
    }
};
