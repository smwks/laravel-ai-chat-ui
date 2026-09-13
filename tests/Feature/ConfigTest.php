<?php

it('merges package defaults', function () {
    expect(config('ai-kit.turns.tables.turns'))->toBe('agent_conversation_turns');
    expect(config('ai-kit.turns.tables.events'))->toBe('agent_conversation_events');
    expect(config('ai-kit.chat.tool_views'))->toBe([]);
    expect(config('ai-kit.chat.queue.name'))->toBe('default');
});
