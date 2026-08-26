@props(['event', 'showIds' => true])

<div class="space-y-4 p-1">
    @include('ai-chat-ui::components.chat.partials.thought-details._header', ['title' => $event->event_type, 'event' => $event, 'showIds' => $showIds])

    <p class="text-sm italic text-zinc-400">No dedicated structured view for this event type — showing raw payload.</p>

    @include('ai-chat-ui::components.chat.partials.thought-details._raw-tab', ['payload' => $event->payload])
</div>
