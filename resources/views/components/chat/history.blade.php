<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Models\Conversation;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;

new class extends Component
{
    use WithPagination;

    /**
     * Whether the component renders its own <h1>. Turn off when the host
     * page already renders its own page title.
     */
    public bool $showHeader = true;

    /**
     * Classes for the component's root element. Defaults to a sensible
     * standalone layout; pass an empty string — or your own classes —
     * when the host page already constrains width/padding.
     */
    public ?string $containerClass = null;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function conversations(): LengthAwarePaginator
    {
        $user = Auth::user();

        return Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->withCount('messages')
            ->when($this->search, fn ($query) => $query->where('title', 'like', '%'.$this->search.'%'))
            ->orderByDesc('updated_at')
            ->paginate(20);
    }

    public function statsFor(Conversation $conversation): array
    {
        abort_unless(Gate::forUser(Auth::user())->allows('view', $conversation), 403);

        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->whereIn('event_type', ['llm.request', 'llm.response'])
            ->get();

        $totalTokens = (int) $events->where('event_type', 'llm.response')->sum(
            fn ($event) => ($event->payload['usage']['prompt_tokens'] ?? 0) + ($event->payload['usage']['completion_tokens'] ?? 0)
        );

        $model = $events->where('event_type', 'llm.request')->first()?->payload['model'] ?? null;

        return ['total_tokens' => $totalTokens, 'model' => $model];
    }

    public function selectConversation(string $conversationId): void
    {
        $conversation = Conversation::findOrFail($conversationId);

        abort_unless(Gate::forUser(Auth::user())->allows('view', $conversation), 403);

        $this->dispatch('ai-chat-ui-conversation-selected', conversationId: $conversationId);
    }
}; ?>

<div class="{{ $containerClass ?? 'w-full p-6' }}" data-ai-chat-ui="root">
    @if ($showHeader)
        <h1 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-zinc-100" data-ai-chat-ui="header">Conversation history</h1>
    @endif

    <input
        wire:model.live.debounce.300ms="search"
        type="text"
        placeholder="Search by title..."
        class="mb-4 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
    >

    <table class="w-full text-left text-sm">
        <thead class="border-b border-zinc-200 text-zinc-400 dark:border-zinc-700">
            <tr>
                <th class="py-2 pr-4">Title</th>
                <th class="py-2 pr-4">Messages</th>
                <th class="py-2 pr-4">Model</th>
                <th class="py-2 pr-4">Tokens</th>
                <th class="py-2">Updated</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($this->conversations as $conversation)
                @php($stats = $this->statsFor($conversation))
                <tr
                    wire:key="conv-{{ $conversation->id }}"
                    wire:click="selectConversation('{{ $conversation->id }}')"
                    class="cursor-pointer border-b border-zinc-100 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-900"
                >
                    <td class="py-2 pr-4 hover:underline">{{ $conversation->title }}</td>
                    <td class="py-2 pr-4">{{ $conversation->messages_count }}</td>
                    <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap">{{ $stats['model'] ?? '—' }}</td>
                    <td class="py-2 pr-4">{{ $stats['total_tokens'] }}</td>
                    <td class="py-2 text-zinc-400 whitespace-nowrap">{{ $conversation->updated_at?->diffForHumans() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4">
        {{ $this->conversations->links() }}
    </div>
</div>
