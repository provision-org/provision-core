<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_web_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('account_id')->unique();
            $table->text('webhook_secret');
            $table->text('api_token');
            $table->string('status')->default('connected');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_web_connections');
    }
};
