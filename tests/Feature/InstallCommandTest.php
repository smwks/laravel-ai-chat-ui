<?php

use Illuminate\Support\Facades\Schema;

it('reports success when the laravel/ai tables already exist', function () {
    $this->artisan('ai-kit:install')
        ->expectsOutputToContain('agent_conversations')
        ->assertExitCode(0);
});

it('fails fast with instructions when the laravel/ai tables are missing', function () {
    Schema::dropIfExists('agent_conversation_messages');
    Schema::dropIfExists('agent_conversations');

    $this->artisan('ai-kit:install')
        ->expectsOutputToContain('vendor:publish --provider="Laravel\Ai\AiServiceProvider"')
        ->assertExitCode(1);
});
