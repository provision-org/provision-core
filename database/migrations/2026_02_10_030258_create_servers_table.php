<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('hetzner_server_id')->nullable()->unique();
            $table->string('ipv4_address')->nullable();
            $table->string('server_type')->default('cx32');
            $table->string('region')->default('nbg1');
            $table->string('image')->default('ubuntu-24.04');
            $table->string('status')->default('provisioning');
            $table->text('gateway_token')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_health_check')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
