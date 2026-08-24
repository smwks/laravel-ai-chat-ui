@props(['event'])

<div x-data="{ tab: 'structured' }" class="space-y-4 p-1">
    @include('ai-chat-ui::components.chat.partials.thought-details._header', ['title' => 'Tool: '.($event->payload['tool'] ?? ''), 'event' => $event])

    <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 text-sm dark:bg-zinc-800">
        <button @click="tab = 'structured'" :class="tab === 'structured' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="flex-1 rounded-md px-3 py-1">Structured</button>
        <button @click="tab = 'raw'" :class="tab === 'raw' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="flex-1 rounded-md px-3 py-1">Raw</button>
    </div>

    <div x-show="tab === 'structured'" class="space-y-4">
        <div class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-sm">
            <span class="text-zinc-400">Duration</span><span>{{ number_format($event->payload['duration_ms'] ?? 0, 1) }} ms</span>
            <span class="text-zinc-400">SQL queries</span><span>{{ count($event->payload['sql_queries'] ?? []) }}</span>
        </div>

        <div>
            <div class="mb-1 text-sm text-zinc-400">Parameters</div>
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-3 text-xs text-zinc-100">{{ json_encode($event->payload['parameters'] ?? [], JSON_PRETTY_PRINT) }}</pre>
        </div>

        <div>
            <div class="mb-1 text-sm text-zinc-400">Result</div>
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-3 text-xs text-zinc-100">{{ is_string($event->payload['result'] ?? null) ? $event->payload['result'] : json_encode($event->payload['result'] ?? null, JSON_PRETTY_PRINT) }}</pre>
        </div>

        @if (! empty($event->payload['sql_queries']))
            <div>
                <div class="mb-1 text-sm text-zinc-400">SQL</div>
                <div class="space-y-2">
                    @foreach ($event->payload['sql_queries'] as $query)
                        <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-3 text-xs text-zinc-100">{{ $query['sql'] }}</pre>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div x-show="tab === 'raw'">
        @include('ai-chat-ui::components.chat.partials.thought-details._raw-tab', ['payload' => $event->payload])
    </div>
</div>
