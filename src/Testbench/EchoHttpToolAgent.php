<?php

namespace Smwks\LaravelAiChatUi\Testbench;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class EchoHttpToolAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'Use the HttpCallingTool once, then acknowledge the user.';
    }

    public function tools(): iterable
    {
        return [new HttpCallingTool];
    }
}
