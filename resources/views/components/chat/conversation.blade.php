<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;
use Smwks\LaravelAiChatUi\Jobs\ProcessChatMessage;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Models\ConversationTurn;

new class extends Component {
    public Conversation $conversation;

    /**
     * Agent class to use for this conversation, e.g. App\Ai\Agents\SupportAgent::class.
     * Falls back to config('ai-chat-ui.agent') when not given — pass this explicitly
     * when a site embeds more than one bot, so each host page pins its own agent
     * rather than sharing the single globally-configured one.
     */
    public ?string $agent = null;

    /**
     * Whether the "show thoughts" trace inspector is available at all. This is
     * ANDed with the viewThoughts policy ability below — both must allow it.
     */
    public bool $showThoughts = true;

    /**
     * Whether the conversation id and each trace event's id are shown (and
     * click-to-copy) in the UI.
     */
    public bool $showIds = true;

    public string $message = '';

    public string $pendingUserMessage = '';

    public ?string $pendingTurnId = null;

    public bool $streaming = false;

    public ?string $selectedEventId = null;

    public bool $showEventDetails = false;

    public function mount(Conversation $conversation, ?string $initialMessage = null): void
    {
        abort_unless(
            Gate::forUser(Auth::user())->allows('view', $conversation),
            403
        );

        $this->conversation = $conversation;

        if ($initialMessage) {
            abort_unless(
                Gate::forUser(Auth::user())->allows('sendMessage', $conversation),
                403
            );

            if (mb_strlen($initialMessage) > 2000) {
                abort(422);
            }

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
            Gate::forUser(Auth::user())->allows('sendMessage', $this->conversation),
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

        ProcessChatMessage::dispatch($turn, $text, $this->resolvedAgentClass());
    }

    protected function resolvedAgentClass(): string
    {
        return $this->agent ?? config('ai-chat-ui.agent');
    }

    public function canViewThoughts(): bool
    {
        return $this->showThoughts
            && Gate::forUser(Auth::user())->allows('viewThoughts', $this->conversation);
    }

    public function checkTurnStatus(): void
    {
        if (!$this->pendingTurnId) {
            return;
        }

        $turn = ConversationTurn::find($this->pendingTurnId);

        if (!$turn || in_array($turn->status, [ConversationTurnStatus::Complete, ConversationTurnStatus::Failed], true)) {
            $this->streaming = false;
            $this->pendingUserMessage = '';
            $this->pendingTurnId = null;
            unset($this->messages, $this->eventsByAssistantMessageId, $this->streamingEvents);
        }
    }

    public function showDetails(string $eventId): void
    {
        abort_unless($this->canViewThoughts(), 403);

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
            ->where('event_type', '!=', 'tool.invoking')
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
        if (!$this->pendingTurnId) {
            return collect();
        }

        return ConversationEvent::where('turn_id', $this->pendingTurnId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The in-progress turn's trace events, minus tool.invoking — that event only
     * exists to feed currentStatus() above while a tool is running; once it lands,
     * the same call's tool.invoked entry carries the full, permanent record.
     */
    public function visibleStreamingEvents(): \Illuminate\Support\Collection
    {
        return $this->streamingEvents->where('event_type', '!=', 'tool.invoking');
    }

    #[Computed]
    public function currentStatus(): string
    {
        $default = 'Thinking…';

        $invokedIds = $this->streamingEvents
            ->where('event_type', 'tool.invoked')
            ->map(fn (ConversationEvent $event) => $event->payload['tool_invocation_id'] ?? null)
            ->filter()
            ->all();

        $pending = $this->streamingEvents
            ->where('event_type', 'tool.invoking')
            ->reverse()
            ->first(fn (ConversationEvent $event) => ! in_array($event->payload['tool_invocation_id'] ?? null, $invokedIds, true));

        return $pending?->payload['status'] ?? $default;
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
            'http.exchange' => 'HTTP',
            'error' => 'Error',
            default => $eventType,
        };
    }

    /**
     * A short, muted hint shown next to the label in the trace list — e.g. the
     * model for an llm.request, the domain for an http.exchange, the tool name
     * for a tool.invoked — without needing to open the details panel.
     */
    public function eventSubtitle(ConversationEvent $event): ?string
    {
        return match ($event->event_type) {
            'llm.request' => implode(' · ', array_filter([
                isset($event->payload['provider']) ? class_basename($event->payload['provider']) : null,
                $event->payload['model'] ?? null,
            ])) ?: null,
            'http.exchange' => implode(' ', array_filter([
                $event->payload['method'] ?? null,
                isset($event->payload['url']) ? parse_url($event->payload['url'], PHP_URL_HOST) : null,
            ])) ?: null,
            'tool.invoked' => $event->payload['tool'] ?? null,
            default => null,
        };
    }

    public function detailView(ConversationEvent $event): string
    {
        if ($event->event_type === 'tool.invoked') {
            $tool = $event->payload['tool'] ?? null;
            $view = $tool ? config("ai-chat-ui.tool_views.{$tool}") : null;

            return $view ?? 'ai-chat-ui::components.chat.partials.thought-details.tool';
        }

        return 'ai-chat-ui::components.chat.partials.thought-details.' . match ($event->event_type) {
                'llm.request' => 'llm-request',
                'llm.response' => 'llm-response',
                'http.exchange' => 'http-exchange',
                default => 'generic',
            };
    }

    public function eventColorClasses(string $eventType): string
    {
        return match ($eventType) {
            'llm.request' => 'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/20',
            'llm.response' => 'border-teal-200 bg-teal-50 dark:border-teal-800 dark:bg-teal-950/20',
            'tool.invoked' => 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/20',
            'http.exchange' => 'border-sky-200 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/20',
            'error' => 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/20',
            default => 'border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40',
        };
    }

    /**
     * How far to indent this event in the trace list, reflecting that
     * llm.request/llm.response bracket one whole prompt() call (which may
     * involve several provider round-trips and tool calls), and that an
     * http.exchange made from inside a tool's own handle() is a child of
     * that tool call rather than a sibling provider step.
     */
    public function eventIndentClass(ConversationEvent $event): string
    {
        if (in_array($event->event_type, ['llm.request', 'llm.response'], true)) {
            return '';
        }

        if ($event->event_type === 'http.exchange') {
            $source = $event->payload['source'] ?? 'provider';

            return $source === 'provider' ? 'ml-6' : 'ml-12';
        }

        if ($event->event_type === 'tool.invoked') {
            return 'ml-6';
        }

        return '';
    }
}; ?>

<div>
    @if ($streaming)
        <div wire:poll.1000ms="checkTurnStatus"></div>
    @endif

    <div class="mx-auto flex h-screen max-w-3xl flex-col p-6">
        <div class="mb-4">
            <h1 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $conversation->title }}</h1>
            @if ($showIds)
                @include('ai-chat-ui::components.chat.partials.copyable-id', ['value' => $conversation->id])
            @endif
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto" id="message-thread">
            @foreach ($this->messages as $msg)
                <div wire:key="msg-{{ $msg->id }}" class="flex flex-col gap-2">
                    @if ($msg->role === 'user')
                        <div
                            class="ml-auto max-w-md rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white dark:bg-zinc-100 dark:text-zinc-900">
                            {{ $msg->content }}
                        </div>
                    @else
                        @php($turnEvents = $this->eventsByAssistantMessageId->get($msg->id, collect()))

                        @if ($this->canViewThoughts() && $turnEvents->isNotEmpty())
                            <div x-data="{ open: false }" class="max-w-md">
                                <button type="button" @click="open = !open"
                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                    <span x-text="open ? '▾ hide thoughts' : '▸ show thoughts'"></span>
                                </button>
                                <div x-show="open" x-cloak class="mt-2 space-y-2">
                                    @foreach ($turnEvents as $event)
                                        <div wire:key="event-{{ $event->id }}"
                                             class="rounded-lg border p-2 text-xs {{ $this->eventColorClasses($event->event_type) }} {{ $this->eventIndentClass($event) }}">
                                            <div class="flex items-center gap-2">
                                                <span class="shrink-0 font-semibold">{{ $this->eventLabel($event->event_type) }}</span>
                                                @if ($subtitle = $this->eventSubtitle($event))
                                                    <span class="min-w-0 flex-1 truncate text-zinc-400">{{ $subtitle }}</span>
                                                @else
                                                    <span class="flex-1"></span>
                                                @endif
                                                <button type="button" wire:click="showDetails('{{ $event->id }}')"
                                                        class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
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
                <div
                    class="ml-auto max-w-md rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white opacity-60 dark:bg-zinc-100 dark:text-zinc-900">
                    {{ $pendingUserMessage }}
                </div>
            @endif

            @if ($streaming)
                @if ($this->canViewThoughts())
                    <div x-data="{ open: false }" class="max-w-md">
                        <button type="button" @click="open = !open"
                                class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <span x-show="!open" class="animate-pulse">▸ {{ $this->currentStatus }}</span>
                            <span x-show="open" x-cloak>▾ hide thoughts</span>
                        </button>
                        <div x-show="open" x-cloak class="mt-2 space-y-2">
                            @foreach ($this->visibleStreamingEvents() as $event)
                                <div wire:key="stream-event-{{ $event->id }}"
                                     class="rounded-lg border p-2 text-xs {{ $this->eventColorClasses($event->event_type) }} {{ $this->eventIndentClass($event) }}">
                                    <div class="flex items-center gap-2">
                                        <span class="shrink-0 font-semibold">{{ $this->eventLabel($event->event_type) }}</span>
                                        @if ($subtitle = $this->eventSubtitle($event))
                                            <span class="min-w-0 flex-1 truncate text-zinc-400">{{ $subtitle }}</span>
                                        @else
                                            <span class="flex-1"></span>
                                        @endif
                                        <button type="button" wire:click="showDetails('{{ $event->id }}')"
                                                class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                            details
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="max-w-md text-xs text-zinc-400">
                        <span class="animate-pulse">{{ $this->currentStatus }}</span>
                    </div>
                @endif
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
            <button type="submit"
                    @disabled($streaming) class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900">
                Send
            </button>
        </form>
    </div>

    <div
        x-show="$wire.showEventDetails"
        x-cloak
        x-transition.opacity.duration.200ms
        class="fixed inset-0 z-50 flex justify-end bg-black/30"
        @keydown.escape.window="$wire.closeDetails()"
    >
        <div
            x-show="$wire.showEventDetails"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @click.outside="$wire.closeDetails()"
            class="h-full w-full max-w-2xl overflow-y-auto bg-white p-6 shadow-xl dark:bg-zinc-900"
        >
            <div class="mb-4 flex justify-end">
                <button type="button" wire:click="closeDetails"
                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">close ✕
                </button>
            </div>

            @if ($this->selectedEvent)
                @include($this->detailView($this->selectedEvent), ['event' => $this->selectedEvent, 'showIds' => $showIds])
            @endif
        </div>
    </div>

    <script src="{{ asset('vendor/ai-chat-ui/json-viewer.min.js') }}" crossorigin="anonymous"></script>
    <script src="{{ asset('vendor/ai-chat-ui/json-tree-search.js') }}" crossorigin="anonymous"></script>
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
