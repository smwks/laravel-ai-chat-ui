<?php

namespace Smwks\LaravelAiChatUi\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\PromptingAgent;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;

class CaptureAgentRequest
{
    public function __invoke(PromptingAgent $event): void
    {
        if (! Context::has('ai-chat-ui.conversation_id')) {
            return;
        }

        $agent = $event->prompt->agent;

        $payload = [
            'invocation_id' => $event->invocationId,
            'provider' => $event->prompt->provider::class,
            'model' => $event->prompt->model,
            'prompt' => $event->prompt->prompt,
            'instructions' => (string) $agent->instructions(),
            'messages' => $agent instanceof Conversational
                ? collect($agent->messages())->map(fn ($m) => [
                    'role' => $m->role->value,
                    'content' => $m->content,
                ])->all()
                : [],
            'attachments' => $event->prompt->attachments->toArray(),
            'timeout' => $event->prompt->timeout,
        ];

        if ($agent instanceof HasTools) {
            $payload['tools'] = collect($agent->tools())
                ->map(fn ($tool) => [
                    'name' => class_basename($tool),
                    'description' => (string) $tool->description(),
                ])
                ->all();
        }

        ConversationEvent::create([
            'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
            'turn_id' => Context::get('ai-chat-ui.turn_id'),
            'event_type' => 'llm.request',
            'payload' => $payload,
        ]);
    }
}
