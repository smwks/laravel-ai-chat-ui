<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('ai-kit.turns.tables.events', 'agent_conversation_events'), function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('turn_id', 36)->nullable()->index();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('ai-kit.turns.tables.events', 'agent_conversation_events'));
    }
};
