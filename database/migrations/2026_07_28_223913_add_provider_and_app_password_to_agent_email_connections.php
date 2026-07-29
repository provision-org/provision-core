<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_email_connections', function (Blueprint $table) {
            // Which backend this agent's mailbox uses. Existing rows are all
            // MailboxKit. "gmail" = customer-supplied Gmail via App Password.
            $table->string('provider')->default('mailboxkit')->after('agent_id');
            // Encrypted Gmail App Password (only set when provider = gmail).
            $table->text('app_password')->nullable()->after('mailboxkit_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('agent_email_connections', function (Blueprint $table) {
            $table->dropColumn(['provider', 'app_password']);
        });
    }
};
