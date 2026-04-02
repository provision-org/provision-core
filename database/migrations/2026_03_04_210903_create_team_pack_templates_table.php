<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_pack_templates', function (Blueprint $table) {
            $table->foreignUlid('team_pack_id')->constrained('team_packs')->cascadeOnDelete();
            $table->foreignUlid('agent_template_id')->constrained('agent_templates')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);

            $table->primary(['team_pack_id', 'agent_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_pack_templates');
    }
};
