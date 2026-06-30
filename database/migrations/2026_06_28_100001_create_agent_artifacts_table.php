<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A published web artifact served from an agent's public subdomain at
     * {agent.slug}.provisionagents.com/{path_slug}. Static artifacts are served
     * from a directory; app artifacts reverse-proxy to a port the agent runs.
     */
    public function up(): void
    {
        Schema::create('agent_artifacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path_slug');
            $table->string('type')->default('static'); // static | app
            $table->string('source_dir')->nullable();   // static: dir under the agent's public root
            $table->string('start_command')->nullable(); // app: command to run
            $table->unsignedInteger('port')->nullable();  // app: reverse-proxy target port
            $table->string('visibility')->default('public'); // public | gated
            $table->string('access_token')->nullable();   // gated: shared link token
            $table->string('status')->default('pending'); // pending | live | error | stopped
            $table->text('error_message')->nullable();
            $table->string('public_url')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'path_slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_artifacts');
    }
};
