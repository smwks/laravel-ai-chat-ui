@props(['title', 'event'])

<div class="flex items-center justify-between gap-2">
    <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $title }}</h3>
    <span class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $event->id }}</span>
</div>
