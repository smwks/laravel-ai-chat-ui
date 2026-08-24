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

<div class="mx-auto flex h-screen max-w-2xl flex-col justify-center gap-6 p-6">
    <h1 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">New conversation</h1>

    <form wire:submit="sendMessage" class="flex flex-col gap-3">
        <textarea
            wire:model="message"
            rows="4"
            placeholder="Ask something..."
            class="w-full rounded-lg border border-zinc-300 p-3 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
        ></textarea>
        @error('message')
            <span class="text-sm text-red-600">{{ $message }}</span>
        @enderror
        <button type="submit" class="self-end rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900">
            Start conversation
        </button>
    </form>
</div>
