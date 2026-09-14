<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class Assistant implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'You are a friendly, concise assistant for this demo app. Keep replies short.';
    }
}
