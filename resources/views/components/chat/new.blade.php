<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Component;

new class extends Component
{
    public string $message = '';

    public function sendMessage(): void
    {
        $this->validate(['message' => 'required|string|max:2000']);

        $user = Auth::user();

        $conversation = Conversation::create([
            'id' => (string) Str::uuid7(),
            'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user),
            'title' => Str::limit($this->message, 60, ''),
        ]);

        $this->dispatch('ai-chat-ui-conversation-started', conversationId: $conversation->id, message: $this->message);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6 py-16">
    <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">New conversation</h1>

    <form wire:submit="sendMessage" class="flex flex-col gap-4">
        <textarea
            wire:model="message"
            rows="6"
            placeholder="Ask something..."
            class="w-full resize-none rounded-xl border border-zinc-300 p-4 text-base shadow-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:focus:ring-zinc-700"
        ></textarea>
        @error('message')
            <span class="text-sm text-red-600">{{ $message }}</span>
        @enderror
        <button type="submit" class="self-end rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900">
            Start conversation
        </button>
    </form>
</div>
