<?php

namespace Smwks\LaravelAiKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InstallCommand extends Command
{
    protected $signature = 'ai-kit:install';

    protected $description = 'Verify laravel/ai\'s conversation tables exist before using ai-kit';

    public function handle(): int
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (Schema::hasTable($conversationsTable) && Schema::hasTable($messagesTable)) {
            $this->info("Found {$conversationsTable} and {$messagesTable} — ai-kit is ready to use.");

            return self::SUCCESS;
        }

        $this->error("Missing laravel/ai's own tables ({$conversationsTable} / {$messagesTable}). Run:");
        $this->line('  php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"');
        $this->line('  php artisan migrate');
        $this->line('then re-run this command.');

        return self::FAILURE;
    }
}
