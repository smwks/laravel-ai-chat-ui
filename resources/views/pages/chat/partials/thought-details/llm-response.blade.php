@props(['event'])

@php
    $usage = $event->payload['usage'] ?? [];
    $meta = $event->payload['meta'] ?? [];
@endphp

<div x-data="{ tab: 'structured' }" class="space-y-4 p-1">
    @include('ai-chat-ui::pages.chat.partials.thought-details._header', ['title' => 'LLM Response', 'event' => $event])

    <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 text-sm dark:bg-zinc-800">
        <button @click="tab = 'structured'" :class="tab === 'structured' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="flex-1 rounded-md px-3 py-1">Structured</button>
        <button @click="tab = 'raw'" :class="tab === 'raw' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="flex-1 rounded-md px-3 py-1">Raw</button>
    </div>

    <div x-show="tab === 'structured'" class="space-y-4">
        <div class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-sm">
            <span class="text-zinc-400">Provider</span><span class="font-mono">{{ $meta['provider'] ?? '—' }}</span>
            <span class="text-zinc-400">Model</span><span class="font-mono">{{ $meta['model'] ?? '—' }}</span>
            <span class="text-zinc-400">Prompt tokens</span><span>{{ $usage['prompt_tokens'] ?? 0 }}</span>
            <span class="text-zinc-400">Completion tokens</span><span>{{ $usage['completion_tokens'] ?? 0 }}</span>
            <span class="text-zinc-400">Cache read / write</span><span>{{ $usage['cache_read_input_tokens'] ?? 0 }} / {{ $usage['cache_write_input_tokens'] ?? 0 }}</span>
        </div>

        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">response text</div>
            <div class="whitespace-pre-wrap text-sm">{{ $event->payload['text'] ?? '' }}</div>
        </div>
    </div>

    <div x-show="tab === 'raw'">
        @include('ai-chat-ui::pages.chat.partials.thought-details._raw-tab', ['payload' => $event->payload])
    </div>
</div>
