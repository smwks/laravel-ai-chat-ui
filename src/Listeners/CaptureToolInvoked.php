<?php

namespace Smwks\LaravelAiChatUi\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\ToolInvoked;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Services\QueryTracker;

class CaptureToolInvoked
{
    public function __construct(protected QueryTracker $tracker) {}

    public function __invoke(ToolInvoked $event): void
    {
        // Always stop tracking, even when we won't write an event — InvokingTool
        // (Task 6's listener above) always calls startTracking(), so every tool
        // call anywhere in the app toggles this shared tracker. Not scoping this
        // stop to a Context check keeps the tracker's internal state clean for
        // the next tool call regardless of who made this one.
        $queries = $this->tracker->stopTracking();

        // Same reasoning as the tracker stop above: always clear these, even when
        // we won't write an event, so a nested/failed tool call never leaves a
        // stale tool_source/tool_invocation_id attributing an unrelated later
        // HTTP call to it.
        Context::forget('ai-chat-ui.tool_source');
        Context::forget('ai-chat-ui.tool_invocation_id');

        if (! Context::has('ai-chat-ui.conversation_id')) {
            return;
        }

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
            'turn_id' => Context::get('ai-chat-ui.turn_id'),
            'event_type' => 'tool.invoked',
            'payload' => [
                'tool' => class_basename($event->tool),
                'tool_invocation_id' => $event->toolInvocationId,
                'parameters' => $event->arguments,
                'result' => $event->result,
                'duration_ms' => $event->time,
                'sql_queries' => $queries,
            ],
        ]);
    }
}
