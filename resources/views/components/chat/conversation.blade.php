<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus;
use Smwks\LaravelAiKit\Chat\Jobs\ProcessChatMessage;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Turns\Models\ConversationTurn;

new class extends Component {
    public Conversation $conversation;

    /**
     * Agent class to use for this conversation, e.g. App\Ai\Agents\SupportAgent::class.
     * Falls back to config('ai-kit.chat.agent') when not given — pass this explicitly
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

    /**
     * Whether the component renders its own <h1> + conversation id header.
     * Turn off when the host page already renders its own page title.
     */
    public bool $showHeader = true;

    /**
     * Classes for the component's root element. Defaults to a sensible
     * standalone layout (centered, max width, padding); pass an empty
     * string — or your own classes — when the host page already
     * constrains width/padding, e.g. a Filament panel page.
     */
    public ?string $containerClass = null;

    /**
     * Opt-in: makes the message thread its own scroll container (filling
     * whatever height the host gives it) with the composer pinned below it,
     * instead of the default unbounded layout that relies on an ancestor to
     * scroll. Only turn this on when the host actually hands the component a
     * bounded height — otherwise the thread has nothing to fill and won't
     * scroll internally at all.
     */
    public bool $fillHeight = false;

    public string $message = '';

    public string $pendingUserMessage = '';

    public ?string $pendingTurnId = null;

    public bool $streaming = false;

    public ?string $selectedEventId = null;

    public bool $showEventDetails = false;

    /**
     * z-index for the trace details slide-over panel. The panel is
     * x-teleport="body"'d to the end of <body>, so raise this when a host
     * app's own modal/toast layer (e.g. Filament's) sits above the default.
     */
    public int $detailsZIndex = 50;

    /**
     * Tool-call id => approve (true) / reject (false), accumulated as the user
     * decides each pending call. Submitted once every pending call in the turn
     * has a decision (see resolveApprovals()).
     *
     * @var array<string, bool>
     */
    public array $approvalDecisions = [];

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
        if ($this->streaming || $this->pendingApprovals !== null) {
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
        return $this->agent ?? config('ai-kit.chat.agent');
    }

    /**
     * The tool calls from the most recent paused turn that still need a
     * decision, or null when nothing is awaiting approval. Each entry is
     * ['id', 'tool', 'arguments', 'reason']; 'messageId' is the paused
     * assistant row the prompt renders beneath.
     *
     * @return array{messageId: string, calls: array<int, array{id: string, tool: string, arguments: array<string, mixed>, reason: ?string}>}|null
     */
    #[Computed]
    public function pendingApprovals(): ?array
    {
        $paused = $this->messages
            ->where('role', 'assistant')
            ->filter(fn (ConversationMessage $message) => filled($message->approval_state['pending'] ?? null))
            ->last();

        if (! $paused) {
            return null;
        }

        // A call answered on any later row means that pause is already resolved.
        $resolvedIds = $this->messages
            ->flatMap(fn (ConversationMessage $message) => collect($message->tool_results ?? [])->pluck('id'))
            ->filter()
            ->all();

        $unresolved = array_values(array_diff(
            array_keys($paused->approval_state['pending']),
            $resolvedIds,
        ));

        if ($unresolved === []) {
            return null;
        }

        $callsById = collect($paused->tool_calls ?? [])->keyBy('id');

        return [
            'messageId' => $paused->id,
            'calls' => collect($unresolved)->map(fn (string $id) => [
                'id' => $id,
                'tool' => $callsById[$id]['name'] ?? 'tool',
                'arguments' => $callsById[$id]['arguments'] ?? [],
                'reason' => $paused->approval_state['pending'][$id] ?: null,
            ])->all(),
        ];
    }

    public function approvePendingCall(string $callId): void
    {
        $this->approvalDecisions[$callId] = true;

        $this->resolveApprovals();
    }

    public function rejectPendingCall(string $callId): void
    {
        $this->approvalDecisions[$callId] = false;

        $this->resolveApprovals();
    }

    public function approveAllPending(): void
    {
        foreach ($this->pendingApprovals['calls'] ?? [] as $call) {
            $this->approvalDecisions[$call['id']] = true;
        }

        $this->resolveApprovals();
    }

    public function rejectAllPending(): void
    {
        foreach ($this->pendingApprovals['calls'] ?? [] as $call) {
            $this->approvalDecisions[$call['id']] = false;
        }

        $this->resolveApprovals();
    }

    /**
     * Submit the accumulated decisions once every pending call in the turn has
     * one: settle the paused turn, start a fresh turn, and dispatch the resume.
     */
    protected function resolveApprovals(): void
    {
        if ($this->streaming) {
            return;
        }

        abort_unless(
            Gate::forUser(Auth::user())->allows('sendMessage', $this->conversation),
            403
        );

        $pending = $this->pendingApprovals;

        if ($pending === null) {
            return;
        }

        $ids = collect($pending['calls'])->pluck('id')->all();
        $decisions = array_intersect_key($this->approvalDecisions, array_flip($ids));

        if (count($decisions) !== count($ids)) {
            return;
        }

        ConversationTurn::where('conversation_id', $this->conversation->id)
            ->where('status', ConversationTurnStatus::AwaitingApproval)
            ->update(['status' => ConversationTurnStatus::Complete]);

        $turn = ConversationTurn::create([
            'conversation_id' => $this->conversation->id,
            'participant_type' => Conversation::participantType(Auth::user()),
            'participant_id' => Conversation::participantKey(Auth::user()),
            'status' => ConversationTurnStatus::Pending,
        ]);

        $this->approvalDecisions = [];
        $this->pendingTurnId = $turn->id;
        $this->streaming = true;

        unset($this->messages, $this->eventsByAssistantMessageId, $this->streamingEvents, $this->pendingApprovals);

        ProcessChatMessage::dispatch($turn, Decisions::from($decisions), $this->resolvedAgentClass());
    }

    public function canViewThoughts(): bool
    {
        return $this->showThoughts
            && Gate::forUser(Auth::user())->allows('viewThoughts', $this->conversation);
    }

    /**
     * A message's content is immutable once written, so its rendered Markdown
     * is cached forever by message id — without this, every message in the
     * thread gets re-parsed on every re-render (e.g. each wire:poll tick
     * while streaming), even though only the newest message actually changed.
     */
    public function renderedMarkdown(ConversationMessage $message): string
    {
        return Cache::rememberForever(
            "ai-kit.rendered-markdown.{$message->id}",
            fn () => Str::markdown($message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false])
        );
    }

    public function checkTurnStatus(): void
    {
        if (!$this->pendingTurnId) {
            return;
        }

        $turn = ConversationTurn::find($this->pendingTurnId);

        if (!$turn || $turn->status->isSettled()) {
            $this->streaming = false;
            $this->pendingUserMessage = '';
            $this->pendingTurnId = null;
            unset($this->messages, $this->eventsByAssistantMessageId, $this->streamingEvents, $this->pendingApprovals);
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
                $result->put($assistantMessage->id, $this->reorderEventsForDisplay($eventsByTurn->get($turn->id, collect())));
            }
        }

        return $result;
    }

    /**
     * Events are stored in strict chronological order, but a tool's own HTTP
     * call finishes (and is written) *during* that tool's handle() — before
     * the tool.invoked event itself, which is only written once handle()
     * returns. Left in raw order, a tool's HTTP child would render above its
     * own Tool box instead of nested under it. This defers each http.exchange
     * event tied to a tool_invocation_id until that invocation's tool.invoked
     * event is emitted, then renders it immediately after — grouped by
     * invocation id rather than tool name, so calling the same tool twice in
     * one turn doesn't mix up which HTTP calls belong to which call.
     */
    protected function reorderEventsForDisplay(\Illuminate\Support\Collection $events): \Illuminate\Support\Collection
    {
        $pendingByInvocation = [];
        $result = collect();

        foreach ($events as $event) {
            $invocationId = $event->payload['tool_invocation_id'] ?? null;

            if ($event->event_type === 'http.exchange' && $invocationId) {
                $pendingByInvocation[$invocationId][] = $event;

                continue;
            }

            $result->push($event);

            if ($event->event_type === 'tool.invoked' && $invocationId && isset($pendingByInvocation[$invocationId])) {
                foreach ($pendingByInvocation[$invocationId] as $child) {
                    $result->push($child);
                }

                unset($pendingByInvocation[$invocationId]);
            }
        }

        // A child whose tool.invoked never showed up (shouldn't normally happen)
        // is still shown, just at the end, rather than silently dropped.
        foreach ($pendingByInvocation as $children) {
            foreach ($children as $child) {
                $result->push($child);
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
        return $this->reorderEventsForDisplay(
            $this->streamingEvents->where('event_type', '!=', 'tool.invoking')
        );
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
            'tool.approval_requested' => 'Approval requested',
            'tool.approval_resolved' => 'Approval resolved',
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
            'tool.approval_requested' => collect($event->payload['approvals'] ?? [])->pluck('tool')->filter()->implode(', ') ?: null,
            'tool.approval_resolved' => collect($event->payload['results'] ?? [])
                ->map(fn ($result) => ($result['tool'] ?? '').(($result['denied'] ?? false) ? ' (rejected)' : ''))
                ->filter()
                ->implode(', ') ?: null,
            default => null,
        };
    }

    public function detailView(ConversationEvent $event): string
    {
        if ($event->event_type === 'tool.invoked') {
            $tool = $event->payload['tool'] ?? null;
            $view = $tool ? config("ai-kit.chat.tool_views.{$tool}") : null;

            return $view ?? 'ai-kit::components.chat.partials.thought-details.tool';
        }

        return 'ai-kit::components.chat.partials.thought-details.' . match ($event->event_type) {
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
            'tool.approval_requested' => 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/20',
            'tool.approval_resolved' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/20',
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

    <div
        class="{{ $containerClass ?? ($fillHeight ? 'mx-auto flex h-full max-w-3xl flex-col p-6' : 'mx-auto flex max-w-3xl flex-col p-6') }}"
        data-ai-kit="root"
    >
        @if ($showHeader)
            <div class="mb-4" data-ai-kit="header">
                <h1 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $conversation->title }}</h1>
                @if ($showIds)
                    @include('ai-kit::components.chat.partials.copyable-id', ['value' => $conversation->id])
                @endif
            </div>
        @endif

        {{-- No fixed height or overflow-y-auto by default on purpose: this component doesn't own
             the viewport, so it can't assume it's safe to scroll internally. A host embedding it
             inside its own already-scrollable region (e.g. a Filament page) would otherwise end up
             with two nested scrollbars fighting over the same content. Whatever ancestor scrolls
             (the page itself, or a host-provided container) is the only scrollbar — unless
             fillHeight is on, which opts into owning its own scroll because the host has explicitly
             handed this component a bounded box to fill. --}}
        <div
            class="{{ $fillHeight ? 'min-h-0 flex-1 space-y-4 overflow-y-auto' : 'space-y-4' }}"
            data-ai-kit="thread"
        >
            @foreach ($this->messages as $msg)
                <div wire:key="msg-{{ $msg->id }}" class="flex flex-col gap-2" data-ai-kit="message" data-ai-kit-role="{{ $msg->role }}">
                    @if ($msg->role === 'user')
                        <div
                            class="ml-auto max-w-md rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white dark:bg-zinc-100 dark:text-zinc-900">
                            {{ $msg->content }}
                        </div>
                    @else
                        @php($turnEvents = $this->eventsByAssistantMessageId->get($msg->id, collect()))

                        @if ($this->canViewThoughts() && $turnEvents->isNotEmpty())
                            <div x-data="{ open: false }" class="max-w-md">
                                <button type="button" @click="open = !open" data-ai-kit="thoughts-toggle"
                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                    <span x-text="open ? '▾ hide thoughts' : '▸ show thoughts'"></span>
                                </button>
                                <div x-show="open" x-cloak class="mt-2 space-y-2">
                                    @foreach ($turnEvents as $event)
                                        <div wire:key="event-{{ $event->id }}" data-ai-kit="thought-event"
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

                        @if (filled($msg->content))
                            <div class="max-w-md rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-700" data-ai-kit="reply-body">
                                {!! $this->renderedMarkdown($msg) !!}
                            </div>
                        @endif

                        @if (($approvals = $this->pendingApprovals) && $approvals['messageId'] === $msg->id)
                            <div class="max-w-md space-y-2" data-ai-kit="approval">
                                @foreach ($approvals['calls'] as $call)
                                    <div wire:key="approval-{{ $call['id'] }}" data-ai-kit="approval-request"
                                         class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/20">
                                        <p class="font-medium text-zinc-900 dark:text-zinc-100">
                                            Run <code class="rounded bg-white/70 px-1 text-xs dark:bg-black/30">{{ $call['tool'] }}</code>?
                                        </p>
                                        @if ($call['reason'])
                                            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{{ $call['reason'] }}</p>
                                        @endif
                                        @if (! empty($call['arguments']))
                                            <pre class="mt-2 overflow-x-auto rounded bg-white/70 p-2 text-xs text-zinc-700 dark:bg-black/30 dark:text-zinc-300">{{ json_encode($call['arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                        @endif
                                        <div class="mt-3 flex gap-2">
                                            <button type="button" wire:click="approvePendingCall('{{ $call['id'] }}')" @disabled($streaming)
                                                    data-ai-kit="approval-approve"
                                                    class="rounded-md bg-zinc-900 px-3 py-1 text-xs font-medium text-white disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900">
                                                Approve
                                            </button>
                                            <button type="button" wire:click="rejectPendingCall('{{ $call['id'] }}')" @disabled($streaming)
                                                    data-ai-kit="approval-reject"
                                                    class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium text-zinc-700 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-200">
                                                Reject
                                            </button>
                                        </div>
                                    </div>
                                @endforeach

                                @if (count($approvals['calls']) > 1)
                                    <div class="flex gap-3 px-1">
                                        <button type="button" wire:click="approveAllPending" @disabled($streaming)
                                                class="text-xs text-zinc-500 hover:text-zinc-800 disabled:opacity-50 dark:hover:text-zinc-200">
                                            Approve all
                                        </button>
                                        <button type="button" wire:click="rejectAllPending" @disabled($streaming)
                                                class="text-xs text-zinc-500 hover:text-zinc-800 disabled:opacity-50 dark:hover:text-zinc-200">
                                            Reject all
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach

            @if ($pendingUserMessage)
                <div
                    class="ml-auto max-w-md rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white opacity-60 dark:bg-zinc-100 dark:text-zinc-900"
                    data-ai-kit="message" data-ai-kit-role="user"
                >
                    {{ $pendingUserMessage }}
                </div>
            @endif

            @if ($streaming)
                @if ($this->canViewThoughts())
                    <div x-data="{ open: false }" class="max-w-md">
                        <button type="button" @click="open = !open" data-ai-kit="thoughts-toggle"
                                class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <span x-show="!open" class="animate-pulse">▸ {{ $this->currentStatus }}</span>
                            <span x-show="open" x-cloak>▾ hide thoughts</span>
                        </button>
                        <div x-show="open" x-cloak class="mt-2 space-y-2">
                            @foreach ($this->visibleStreamingEvents() as $event)
                                <div wire:key="stream-event-{{ $event->id }}" data-ai-kit="thought-event"
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

        @php($composerDisabled = $streaming || $this->pendingApprovals !== null)

        <form wire:submit="sendMessage" class="mt-4 flex gap-2" data-ai-kit="composer">
            <textarea
                wire:model="message"
                rows="1"
                placeholder="{{ $this->pendingApprovals !== null ? 'Resolve the pending approval to continue…' : 'Type a message...' }}"
                @disabled($composerDisabled)
                x-data
                x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage() }"
                class="max-h-40 flex-1 resize-none overflow-y-auto rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            ></textarea>
            <button type="submit"
                    @disabled($composerDisabled) class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900">
                Send
            </button>
        </form>
    </div>

    <div
        x-teleport="body"
        x-show="$wire.showEventDetails"
        x-cloak
        x-transition.opacity.duration.200ms
        class="fixed inset-0 flex justify-end bg-black/30"
        style="z-index: {{ $detailsZIndex }}"
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

    @assets
        <script src="{{ asset('vendor/ai-kit/json-viewer.min.js') }}" crossorigin="anonymous"></script>
        <script src="{{ asset('vendor/ai-kit/json-tree-search.js') }}" crossorigin="anonymous"></script>
        <link rel="stylesheet" href="{{ asset('vendor/ai-kit/reply-body.css') }}" crossorigin="anonymous">
    @endassets

    @script
        <script>
            // $el is this component's own root element — scoped per instance, unlike a
            // global id, so two chat.conversation components on one page each scroll
            // their own thread rather than fighting over the same #message-thread.
            function scrollAiKitThreadToBottom() {
                const thread = $el.querySelector('[data-ai-kit="thread"]');
                if (thread) thread.scrollTop = thread.scrollHeight;
            }

            document.addEventListener('livewire:navigated', scrollAiKitThreadToBottom);
            document.addEventListener('livewire:update', scrollAiKitThreadToBottom);

            scrollAiKitThreadToBottom();
        </script>
    @endscript
</div>
