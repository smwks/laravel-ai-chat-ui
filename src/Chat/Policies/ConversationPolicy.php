<?php

namespace Smwks\LaravelAiKit\Chat\Policies;

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

    /**
     * Whether the user may see the "show thoughts" trace inspector for this
     * conversation. Defaults to the same rule as view() — override this in your
     * own policy (e.g. restrict to admins) if thoughts should be visible to fewer
     * people than the conversation itself.
     */
    public function viewThoughts(Authenticatable $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
