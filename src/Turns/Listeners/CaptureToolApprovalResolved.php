<?php

namespace Smwks\LaravelAiKit\Turns\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Responses\Data\ToolResult;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;

class CaptureToolApprovalResolved
{
    public function __invoke(ToolApprovalResolved $event): void
    {
        if (! Context::has('ai-kit.conversation_id')) {
            return;
        }

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-kit.conversation_id'),
            'turn_id' => Context::get('ai-kit.turn_id'),
            'event_type' => 'tool.approval_resolved',
            'payload' => [
                'invocation_id' => $event->invocationId,
                'results' => $event->toolResults
                    ->map(fn (ToolResult $result) => [
                        'id' => $result->id,
                        'tool' => $result->name,
                        'denied' => $result->denied,
                        'result' => $result->result,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
