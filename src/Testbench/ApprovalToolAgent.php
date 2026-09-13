<?php

namespace Smwks\LaravelAiKit\Testbench;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class ApprovalToolAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'Use the ApprovalTool once, then acknowledge the user.';
    }

    public function tools(): iterable
    {
        return [new ApprovalTool];
    }
}
