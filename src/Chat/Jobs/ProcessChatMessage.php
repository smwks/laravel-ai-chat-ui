<?php

namespace Smwks\LaravelAiKit\Chat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Approvals\Decisions;
use Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus;
use Smwks\LaravelAiKit\Turns\Models\ConversationTurn;
use Smwks\LaravelAiKit\Turns\Services\QueryTracker;
use Throwable;

class ProcessChatMessage implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @param  string|Decisions  $message  The user's message, or tool-approval decisions when resuming a paused turn.
     */
    public function __construct(public ConversationTurn $turn, public string|Decisions $message, public string $agentClass)
    {
        $this->onConnection(config('ai-kit.chat.queue.connection'));
        $this->onQueue(config('ai-kit.chat.queue.name', 'default'));
    }

    public function handle(): void
    {
        app(QueryTracker::class)->stopTracking();

        $this->turn->update(['status' => ConversationTurnStatus::Processing]);

        Context::add('ai-kit.conversation_id', $this->turn->conversation_id);
        Context::add('ai-kit.turn_id', $this->turn->id);

        try {
            $agent = app($this->agentClass);

            $response = $agent->continue($this->turn->conversation_id, as: $this->turn->participant)
                ->prompt($this->message);

            $this->turn->update([
                'status' => $response->hasPendingApprovals()
                    ? ConversationTurnStatus::AwaitingApproval
                    : ConversationTurnStatus::Complete,
            ]);
        } catch (Throwable $e) {
            $this->turn->update(['status' => ConversationTurnStatus::Failed]);

            throw $e;
        }
    }
}
