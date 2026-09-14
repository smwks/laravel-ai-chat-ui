<?php

use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Chat history')] class extends Component
{
    #[On('ai-kit-conversation-selected')]
    public function onConversationSelected(string $conversationId): void
    {
        $this->redirect(route('chat.conversation', $conversationId), navigate: true);
    }
}; ?>

<x-layouts::app :title="__('Chat history')">
    <livewire:ai-kit::components.chat.history />
</x-layouts::app>
