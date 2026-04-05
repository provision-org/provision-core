<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUlid('parent_task_id')->nullable()->after('id')
                ->constrained('tasks')->nullOnDelete();
            $table->foreignUlid('goal_id')->nullable()->after('agent_id')
                ->constrained('goals')->nullOnDelete();
            $table->string('checked_out_by_run')->nullable()->after('sort_order');
            $table->timestamp('checked_out_at')->nullable()->after('checked_out_by_run');
            $table->timestamp('checkout_expires_at')->nullable()->after('checked_out_at');
            $table->foreignUlid('delegated_by')->nullable()->after('checkout_expires_at')
                ->constrained('agents')->nullOnDelete();
            $table->integer('request_depth')->default(0)->after('delegated_by');
            $table->bigInteger('tokens_input')->default(0)->after('request_depth');
            $table->bigInteger('tokens_output')->default(0)->after('tokens_input');
            $table->text('result_summary')->nullable()->after('tokens_output');
            $table->timestamp('started_at')->nullable()->after('result_summary');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['parent_task_id']);
            $table->dropForeign(['goal_id']);
            $table->dropForeign(['delegated_by']);
            $table->dropColumn([
                'parent_task_id',
                'goal_id',
                'checked_out_by_run',
                'checked_out_at',
                'checkout_expires_at',
                'delegated_by',
                'request_depth',
                'tokens_input',
                'tokens_output',
                'result_summary',
                'started_at',
            ]);
        });
    }
};
