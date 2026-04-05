<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignUlid('owner_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('priority')->default('medium');
            $table->date('target_date')->nullable();
            $table->integer('progress_pct')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index('parent_id');
            $table->index('owner_agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
