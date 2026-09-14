<?php

use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New conversation')] class extends Component
{
    #[On('ai-kit-conversation-started')]
    public function onConversationStarted(string $conversationId, string $message): void
    {
        session()->flash('chat.initial_message', $message);

        $this->redirect(route('chat.conversation', $conversationId), navigate: true);
    }
}; ?>

<x-layouts::app :title="__('New conversation')">
    <livewire:ai-kit::components.chat.new />
</x-layouts::app>
