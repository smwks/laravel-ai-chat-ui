<?php

namespace Smwks\LaravelAiKit\Testbench;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class EchoAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'Briefly acknowledge and lightly rephrase what the user just said, in one or two short sentences. Do not ask follow-up questions.';
    }
}
