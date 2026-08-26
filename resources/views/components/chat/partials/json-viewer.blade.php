@props(['value'])

<div x-data="{ search: '' }" class="overflow-hidden rounded-lg border border-zinc-200">
    <div class="border-b border-zinc-200 bg-zinc-50 p-2">
        <input
            type="text"
            x-model="search"
            x-on:input="window.aiChatUiJsonSearch($refs.tree, search)"
            placeholder="Search…"
            class="w-full rounded border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-none"
        >
    </div>

    <div x-ref="tree" class="overflow-x-auto bg-white p-3 font-mono text-xs leading-6 text-zinc-900">
        @include('ai-chat-ui::components.chat.partials.json-viewer-node', ['value' => $value, 'depth' => 0, 'keyName' => null, 'isLast' => true])
    </div>
</div>
