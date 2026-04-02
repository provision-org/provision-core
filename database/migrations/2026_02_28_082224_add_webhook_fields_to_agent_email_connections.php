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
        Schema::table('agent_email_connections', function (Blueprint $table) {
            $table->string('mailboxkit_webhook_id')->nullable();
            $table->text('mailboxkit_webhook_secret')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_email_connections', function (Blueprint $table) {
            $table->dropColumn(['mailboxkit_webhook_id', 'mailboxkit_webhook_secret']);
        });
    }
};
