<?php

namespace Smwks\LaravelAiKit\Turns\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Responses\AgentResponse;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;

class CaptureAgentResponse
{
    public function __invoke(AgentPrompted $event): void
    {
        if (! Context::has('ai-chat-ui.conversation_id')) {
            return;
        }

        if (! $event->response instanceof AgentResponse) {
            return;
        }

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
            'turn_id' => Context::get('ai-chat-ui.turn_id'),
            'event_type' => 'llm.response',
            'payload' => [
                'invocation_id' => $event->invocationId,
                'text' => $event->response->text,
                'usage' => $event->response->usage->toArray(),
                'meta' => $event->response->meta->toArray(),
            ],
        ]);
    }
}
