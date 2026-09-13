@props(['title', 'event', 'showIds' => true])

<div class="flex items-center justify-between gap-2">
    <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $title }}</h3>
    @if ($showIds)
        @include('ai-kit::components.chat.partials.copyable-id', ['value' => $event->id])
    @endif
</div>
