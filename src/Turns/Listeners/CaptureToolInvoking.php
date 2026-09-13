<?php

namespace Smwks\LaravelAiKit\Turns\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\InvokingTool;
use Smwks\LaravelAiKit\Turns\Contracts\HasStatusMessage;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Turns\Services\QueryTracker;

class CaptureToolInvoking
{
    public function __construct(protected QueryTracker $tracker) {}

    public function __invoke(InvokingTool $event): void
    {
        $this->tracker->startTracking();

        // Set for the duration of the tool's handle() call so any outbound HTTP
        // request captured by the global HTTP middleware in that window can be
        // attributed to this tool rather than mistaken for a provider call, and
        // grouped under the correct call if the same tool runs more than once
        // in one turn.
        Context::add('ai-kit.tool_source', class_basename($event->tool));
        Context::add('ai-kit.tool_invocation_id', $event->toolInvocationId);

        if (! Context::has('ai-kit.conversation_id')) {
            return;
        }

        $status = $event->tool instanceof HasStatusMessage
            ? $event->tool->statusMessage($event->arguments)
            : (string) $event->tool->description();

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-kit.conversation_id'),
            'turn_id' => Context::get('ai-kit.turn_id'),
            'event_type' => 'tool.invoking',
            'payload' => [
                'tool' => class_basename($event->tool),
                'tool_invocation_id' => $event->toolInvocationId,
                'status' => $status,
            ],
        ]);
    }
}
