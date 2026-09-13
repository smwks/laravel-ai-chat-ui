<?php

namespace Smwks\LaravelAiKit\Turns\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Events\ToolApprovalRequested;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;

class CaptureToolApprovalRequested
{
    public function __invoke(ToolApprovalRequested $event): void
    {
        if (! Context::has('ai-chat-ui.conversation_id')) {
            return;
        }

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
            'turn_id' => Context::get('ai-chat-ui.turn_id'),
            'event_type' => 'tool.approval_requested',
            'payload' => [
                'invocation_id' => $event->invocationId,
                'approvals' => $event->pendingApprovals
                    ->map(fn (PendingApproval $approval) => [
                        'id' => $approval->id,
                        'tool' => $approval->tool,
                        'arguments' => $approval->arguments,
                        'reason' => $approval->reason,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
