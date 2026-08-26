@props(['event'])

@php
    $duration = $event->payload['duration_ms'] ?? null;
    $status = $event->payload['status'] ?? null;
@endphp

<div x-data="{ side: 'request' }" class="space-y-4 p-1">
    @include('ai-chat-ui::components.chat.partials.thought-details._header', ['title' => 'HTTP Exchange', 'event' => $event])

    <div class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1 text-sm">
        <span class="text-zinc-400">Method</span>
        <span class="font-mono">{{ $event->payload['method'] ?? '' }} {{ $event->payload['url'] ?? '' }}</span>
        <span class="text-zinc-400">Status</span>
        <span class="font-mono font-semibold {{ $status && $status >= 400 ? 'text-red-600 dark:text-red-400' : 'text-teal-600 dark:text-teal-400' }}">{{ $status ?? '—' }}</span>
        <span class="text-zinc-400">Latency</span>
        <span>{{ $duration !== null ? number_format($duration, 1).'ms' : '—' }}</span>
    </div>

    <div class="inline-flex gap-1 rounded-lg bg-zinc-100 p-1 text-sm dark:bg-zinc-800">
        <button @click="side = 'request'" :class="side === 'request' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="rounded-md px-3 py-1">Request</button>
        <button @click="side = 'response'" :class="side === 'response' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="rounded-md px-3 py-1">Response</button>
    </div>

    <div x-show="side === 'request'" class="space-y-4">
        <div>
            <div class="mb-1 text-sm text-zinc-400">Body</div>
            @include('ai-chat-ui::components.chat.partials.json-viewer', ['value' => $event->payload['request']['body'] ?? null])
        </div>

        @include('ai-chat-ui::components.chat.partials.thought-details._headers-table', ['headers' => $event->payload['request']['headers'] ?? []])
    </div>

    <div x-show="side === 'response'" class="space-y-4">
        <div>
            <div class="mb-1 text-sm text-zinc-400">Body</div>
            @include('ai-chat-ui::components.chat.partials.json-viewer', ['value' => $event->payload['response']['body'] ?? null])
        </div>

        @include('ai-chat-ui::components.chat.partials.thought-details._headers-table', ['headers' => $event->payload['response']['headers'] ?? []])
    </div>
</div>
