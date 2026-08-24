@props(['event'])

<div class="space-y-4 p-1">
    @include('ai-chat-ui::pages.chat.partials.thought-details._header', ['title' => $event->event_type, 'event' => $event])

    <p class="text-sm italic text-zinc-400">No dedicated structured view for this event type — showing raw payload.</p>

    @include('ai-chat-ui::pages.chat.partials.thought-details._raw-tab', ['payload' => $event->payload])
</div>
