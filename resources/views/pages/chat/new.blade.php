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

        session()->put('ai-chat-ui.initial_message', $this->message);

        $this->redirect(route(config('ai-chat-ui.routes.names.conversation', 'chat.conversation'), $conversation), navigate: true);
    }
}; ?>

<div class="mx-auto flex h-screen max-w-2xl flex-col justify-center gap-6 p-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">New conversation</h1>
        <a href="{{ route(config('ai-chat-ui.routes.names.history', 'chat.history')) }}"
           class="text-sm text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
            History
        </a>
    </div>

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
