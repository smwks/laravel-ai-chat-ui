<?php

use Smwks\LaravelAiKit\Testbench\EchoAgent;

it('merges package defaults', function () {
    expect(config('ai-chat-ui.tables.turns'))->toBe('agent_conversation_turns');
    expect(config('ai-chat-ui.tables.events'))->toBe('agent_conversation_events');
    expect(config('ai-chat-ui.agent'))->toBe(EchoAgent::class);
    expect(config('ai-chat-ui.tool_views'))->toBe([]);
    expect(config('ai-chat-ui.queue.name'))->toBe('default');
});
