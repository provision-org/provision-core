<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_work_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUlid('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('type')->default('file');
            $table->string('title');
            $table->text('file_path')->nullable();
            $table->text('url')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_work_products');
    }
};
