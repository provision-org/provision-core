<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_email_connections', function (Blueprint $table) {
            $table->renameColumn('sendook_inbox_id', 'mailboxkit_inbox_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_email_connections', function (Blueprint $table) {
            $table->renameColumn('mailboxkit_inbox_id', 'sendook_inbox_id');
        });
    }
};
