<?php

namespace Smwks\LaravelAiKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InstallCommand extends Command
{
    protected $signature = 'ai-kit:install';

    protected $description = 'Verify laravel/ai\'s and this package\'s own conversation tables exist before using ai-kit';

    public function handle(): int
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $turnsTable = config('ai-kit.turns.tables.turns', 'agent_conversation_turns');
        $eventsTable = config('ai-kit.turns.tables.events', 'agent_conversation_events');

        if (! Schema::hasTable($conversationsTable) || ! Schema::hasTable($messagesTable)) {
            $this->error("Missing laravel/ai's own tables ({$conversationsTable} / {$messagesTable}). Run:");
            $this->line('  php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"');
            $this->line('  php artisan migrate');
            $this->line('then re-run this command.');

            return self::FAILURE;
        }

        if (! Schema::hasTable($turnsTable) || ! Schema::hasTable($eventsTable)) {
            $this->error("Missing ai-kit's own tables ({$turnsTable} / {$eventsTable}). Run:");
            $this->line('  php artisan vendor:publish --tag=ai-kit-migrations');
            $this->line('  php artisan migrate');
            $this->line('then re-run this command.');

            return self::FAILURE;
        }

        $this->info("Found {$conversationsTable}, {$messagesTable}, {$turnsTable}, and {$eventsTable} — ai-kit is ready to use.");

        return self::SUCCESS;
    }
}
