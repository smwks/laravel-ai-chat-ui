<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('ai-kit.turns.tables.turns', 'agent_conversation_turns'), function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('ai-kit.turns.tables.turns', 'agent_conversation_turns'));
    }
};
