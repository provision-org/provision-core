<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_email_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mailboxkit_domain_id')->index();
            $table->string('name')->unique();
            $table->boolean('is_verified')->default(false);
            $table->boolean('mx_verified')->default(false);
            $table->boolean('spf_verified')->default(false);
            $table->boolean('dkim_verified')->default(false);
            $table->boolean('dmarc_verified')->default(false);
            $table->json('dns_records')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_email_domains');
    }
};
