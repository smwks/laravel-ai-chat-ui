@props(['event', 'showIds' => true])

@php
    $providerParts = explode('\\', $event->payload['provider'] ?? '');
    $messages = $event->payload['messages'] ?? [];
    $tools = $event->payload['tools'] ?? [];
@endphp

<div x-data="{ tab: 'structured' }" class="space-y-4 p-1">
    @include('ai-kit::components.chat.partials.thought-details._header', ['title' => 'LLM Request', 'event' => $event, 'showIds' => $showIds])

    <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 text-sm dark:bg-zinc-800">
        <button @click="tab = 'structured'" :class="tab === 'structured' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="flex-1 rounded-md px-3 py-1">Structured</button>
        <button @click="tab = 'raw'" :class="tab === 'raw' ? 'bg-white shadow dark:bg-zinc-700' : ''" class="flex-1 rounded-md px-3 py-1">Raw</button>
    </div>

    <div x-show="tab === 'structured'" class="space-y-4">
        <div class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-sm">
            <span class="text-zinc-400">Provider</span><span class="font-mono">{{ end($providerParts) }}</span>
            <span class="text-zinc-400">Model</span><span class="font-mono">{{ $event->payload['model'] ?? '—' }}</span>
            <span class="text-zinc-400">Messages</span><span>{{ count($messages) }}</span>
            <span class="text-zinc-400">Tools</span><span>{{ count($tools) }}</span>
        </div>

        <div class="space-y-2">
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">system</div>
                <div class="whitespace-pre-wrap text-sm">{{ $event->payload['instructions'] ?? '' }}</div>
            </div>

            @foreach ($messages as $message)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ $message['role'] ?? 'unknown' }}</div>
                    <div class="whitespace-pre-wrap text-sm">{{ $message['content'] ?? '' }}</div>
                </div>
            @endforeach

            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-950/20">
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-blue-500">user (this turn)</div>
                <div class="whitespace-pre-wrap text-sm">{{ $event->payload['prompt'] ?? '' }}</div>
            </div>
        </div>

        @if (! empty($tools))
            <div>
                <div class="mb-1 text-sm text-zinc-400">Tools</div>
                <div class="space-y-1">
                    @foreach ($tools as $tool)
                        <div class="flex gap-2 text-sm">
                            <span class="shrink-0 font-mono text-amber-700 dark:text-amber-400">{{ $tool['name'] }}</span>
                            <span class="text-zinc-500">{{ $tool['description'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div x-show="tab === 'raw'">
        @include('ai-kit::components.chat.partials.json-viewer', ['value' => $event->payload])
    </div>
</div>
