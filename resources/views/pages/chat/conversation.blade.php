<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;
use Smwks\LaravelAiChatUi\Jobs\ProcessChatMessage;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Models\ConversationTurn;

new class extends Component
{
    public Conversation $conversation;

    public string $message = '';

    public string $pendingUserMessage = '';

    public ?string $pendingTurnId = null;

    public bool $streaming = false;

    public ?string $selectedEventId = null;

    public bool $showEventDetails = false;

    public function mount(Conversation $conversation): void
    {
        abort_unless(
            \Illuminate\Support\Facades\Gate::forUser(Auth::user())->allows('view', $conversation),
            403
        );

        $this->conversation = $conversation;

        $initialMessage = session()->pull('ai-chat-ui.initial_message');

        if ($initialMessage) {
            $this->dispatchTurn($initialMessage);

            return;
        }

        $pendingTurn = ConversationTurn::where('conversation_id', $conversation->id)
            ->whereIn('status', [ConversationTurnStatus::Pending, ConversationTurnStatus::Processing])
            ->latest('created_at')
            ->first();

        if ($pendingTurn) {
            $this->streaming = true;
            $this->pendingTurnId = $pendingTurn->id;
        }
    }

    public function sendMessage(): void
    {
        if ($this->streaming) {
            return;
        }

        abort_unless(
            \Illuminate\Support\Facades\Gate::forUser(Auth::user())->allows('sendMessage', $this->conversation),
            403
        );

        $this->validate(['message' => 'required|string|max:2000']);

        $text = $this->message;
        $this->message = '';

        $this->dispatchTurn($text);
    }

    protected function dispatchTurn(string $text): void
    {
        $turn = ConversationTurn::create([
            'conversation_id' => $this->conversation->id,
            'participant_type' => Conversation::participantType(Auth::user()),
            'participant_id' => Conversation::participantKey(Auth::user()),
            'status' => ConversationTurnStatus::Pending,
        ]);

        $this->pendingUserMessage = $text;
        $this->pendingTurnId = $turn->id;
        $this->streaming = true;

        ProcessChatMessage::dispatch($turn, $text);
    }

    public function checkTurnStatus(): void
    {
        if (! $this->pendingTurnId) {
            return;
        }

        $turn = ConversationTurn::find($this->pendingTurnId);

        if (! $turn || in_array($turn->status, [ConversationTurnStatus::Complete, ConversationTurnStatus::Failed], true)) {
            $this->streaming = false;
            $this->pendingUserMessage = '';
            $this->pendingTurnId = null;
            unset($this->messages, $this->eventsByAssistantMessageId, $this->streamingEvents);
        }
    }

    public function showDetails(string $eventId): void
    {
        $this->selectedEventId = $eventId;
        $this->showEventDetails = true;
    }

    public function closeDetails(): void
    {
        $this->showEventDetails = false;
    }

    #[Computed]
    public function messages(): \Illuminate\Support\Collection
    {
        return $this->conversation->messages()->orderBy('created_at')->get();
    }

    #[Computed]
    public function eventsByAssistantMessageId(): \Illuminate\Support\Collection
    {
        $turns = ConversationTurn::where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->get();

        $assistantMessages = $this->messages->where('role', 'assistant')->values();

        $eventsByTurn = ConversationEvent::where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->get()
            ->groupBy('turn_id');

        $result = collect();

        foreach ($turns as $index => $turn) {
            $assistantMessage = $assistantMessages->get($index);

            if ($assistantMessage) {
                $result->put($assistantMessage->id, $eventsByTurn->get($turn->id, collect()));
            }
        }

        return $result;
    }

    #[Computed]
    public function streamingEvents(): \Illuminate\Support\Collection
    {
        if (! $this->pendingTurnId) {
            return collect();
        }

        return ConversationEvent::where('turn_id', $this->pendingTurnId)
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function selectedEvent(): ?ConversationEvent
    {
        return $this->selectedEventId
            ? ConversationEvent::where('conversation_id', $this->conversation->id)->find($this->selectedEventId)
            : null;
    }

    public function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            'llm.request' => 'LLM Request',
            'llm.response' => 'LLM Response',
            'tool.invoked' => 'Tool',
            'http.request' => 'HTTP →',
            'http.response' => 'HTTP ←',
            'error' => 'Error',
            default => $eventType,
        };
    }

    public function eventColorClasses(string $eventType): string
    {
        return match ($eventType) {
            'llm.request' => 'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/20',
            'llm.response' => 'border-teal-200 bg-teal-50 dark:border-teal-800 dark:bg-teal-950/20',
            'tool.invoked' => 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/20',
            'http.request' => 'border-sky-200 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/20',
            'http.response' => 'border-teal-200 bg-teal-50 dark:border-teal-800 dark:bg-teal-950/20',
            'error' => 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/20',
            default => 'border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40',
        };
    }
}; ?>

<div>
    @if ($streaming)
        <div wire:poll.1000ms="checkTurnStatus"></div>
    @endif

    <div class="mx-auto flex h-screen max-w-3xl flex-col p-6">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $conversation->title }}</h1>
                <span class="font-mono text-xs text-zinc-400">{{ $conversation->id }}</span>
            </div>
            <a href="{{ route(config('ai-chat-ui.routes.names.new', 'chat.new')) }}" class="text-sm text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400">
                New conversation
            </a>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto" id="message-thread">
            @foreach ($this->messages as $msg)
                <div wire:key="msg-{{ $msg->id }}" class="flex flex-col gap-2">
                    @if ($msg->role === 'user')
                        <div class="ml-auto max-w-md rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white dark:bg-zinc-100 dark:text-zinc-900">
                            {{ $msg->content }}
                        </div>
                    @else
                        @php($turnEvents = $this->eventsByAssistantMessageId->get($msg->id, collect()))

                        @if ($turnEvents->isNotEmpty())
                            <div x-data="{ open: false }" class="max-w-md">
                                <button type="button" @click="open = !open" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                    <span x-text="open ? '▾ hide thoughts' : '▸ show thoughts'"></span>
                                </button>
                                <div x-show="open" x-cloak class="mt-2 space-y-2">
                                    @foreach ($turnEvents as $event)
                                        <div wire:key="event-{{ $event->id }}" class="rounded-lg border p-2 text-xs {{ $this->eventColorClasses($event->event_type) }}">
                                            <div class="flex items-center justify-between">
                                                <span class="font-semibold">{{ $this->eventLabel($event->event_type) }}</span>
                                                <button type="button" wire:click="showDetails('{{ $event->id }}')" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                                    details
                                                </button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="max-w-md rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-700">
                            {!! Str::markdown($msg->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($pendingUserMessage)
                <div class="ml-auto max-w-md rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white opacity-60 dark:bg-zinc-100 dark:text-zinc-900">
                    {{ $pendingUserMessage }}
                </div>
            @endif

            @if ($streaming)
                <div class="max-w-md space-y-2">
                    <div class="flex items-center gap-2 rounded-lg border border-zinc-200 px-4 py-2 text-sm text-zinc-500 dark:border-zinc-700">
                        <span class="animate-pulse">Thinking…</span>
                    </div>
                    @foreach ($this->streamingEvents as $event)
                        <div wire:key="stream-event-{{ $event->id }}" class="rounded-lg border p-2 text-xs {{ $this->eventColorClasses($event->event_type) }}">
                            {{ $this->eventLabel($event->event_type) }}
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <form wire:submit="sendMessage" class="mt-4 flex gap-2">
            <input
                wire:model="message"
                type="text"
                placeholder="Type a message..."
                @disabled($streaming)
                class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
            <button type="submit" @disabled($streaming) class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900">
                Send
            </button>
        </form>
    </div>

    <div
        x-show="$wire.showEventDetails"
        x-cloak
        class="fixed inset-0 z-50 flex justify-end bg-black/30"
        @keydown.escape.window="$wire.closeDetails()"
    >
        <div @click.outside="$wire.closeDetails()" class="h-full w-full max-w-2xl overflow-y-auto bg-white p-6 shadow-xl dark:bg-zinc-900">
            <div class="mb-4 flex justify-end">
                <button type="button" wire:click="closeDetails" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">close ✕</button>
            </div>

            @if ($this->selectedEvent)
                @include('ai-chat-ui::pages.chat.partials.thought-details.' . match ($this->selectedEvent->event_type) {
                    'llm.request' => 'llm-request',
                    'llm.response' => 'llm-response',
                    'tool.invoked' => 'tool',
                    default => 'generic',
                }, ['event' => $this->selectedEvent])
            @endif
        </div>
    </div>

    <script src="{{ asset('vendor/ai-chat-ui/json-viewer.min.js') }}" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('livewire:navigated', () => scrollAiChatUiThreadToBottom());
        document.addEventListener('livewire:update', () => scrollAiChatUiThreadToBottom());
        function scrollAiChatUiThreadToBottom() {
            const thread = document.getElementById('message-thread');
            if (thread) thread.scrollTop = thread.scrollHeight;
        }
        scrollAiChatUiThreadToBottom();
    </script>
</div>
