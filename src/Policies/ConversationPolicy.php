<?php

namespace Smwks\LaravelAiChatUi\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Models\Conversation;

class ConversationPolicy
{
    public function sendMessage(Authenticatable $user, Conversation $conversation): bool
    {
        return $conversation->participant_type === Conversation::participantType($user)
            && (string) $conversation->participant_id === (string) Conversation::participantKey($user);
    }

    public function view(Authenticatable $user, Conversation $conversation): bool
    {
        return $this->sendMessage($user, $conversation);
    }
}
