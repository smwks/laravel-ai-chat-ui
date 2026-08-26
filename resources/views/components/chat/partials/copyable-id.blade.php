@props(['value'])

<span
    x-data="{ copied: false }"
    x-on:click="navigator.clipboard.writeText(@js((string) $value)); copied = true; setTimeout(() => copied = false, 1500)"
    :title="copied ? 'Copied!' : 'Click to copy'"
    class="cursor-pointer font-mono text-xs text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
>
    <span x-show="!copied">{{ $value }}</span>
    <span x-show="copied" x-cloak>Copied!</span>
</span>
