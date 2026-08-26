<?php

namespace Smwks\LaravelAiChatUi\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\InvokingTool;
use Smwks\LaravelAiChatUi\Contracts\HasStatusMessage;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Services\QueryTracker;

class CaptureToolInvoking
{
    public function __construct(protected QueryTracker $tracker) {}

    public function __invoke(InvokingTool $event): void
    {
        $this->tracker->startTracking();

        // Set for the duration of the tool's handle() call so any outbound HTTP
        // request captured by the global HTTP middleware in that window can be
        // attributed to this tool rather than mistaken for a provider call.
        Context::add('ai-chat-ui.tool_source', class_basename($event->tool));

        if (! Context::has('ai-chat-ui.conversation_id')) {
            return;
        }

        $status = $event->tool instanceof HasStatusMessage
            ? $event->tool->statusMessage($event->arguments)
            : (string) $event->tool->description();

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
            'turn_id' => Context::get('ai-chat-ui.turn_id'),
            'event_type' => 'tool.invoking',
            'payload' => [
                'tool' => class_basename($event->tool),
                'tool_invocation_id' => $event->toolInvocationId,
                'status' => $status,
            ],
        ]);
    }
}
