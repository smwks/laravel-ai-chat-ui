<?php

use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

it('merges package defaults', function () {
    expect(config('ai-chat-ui.tables.turns'))->toBe('ai_chat_ui_turns');
    expect(config('ai-chat-ui.tables.events'))->toBe('ai_chat_ui_events');
    expect(config('ai-chat-ui.agent'))->toBe(EchoAgent::class);
    expect(config('ai-chat-ui.routes.enabled'))->toBeTrue();
    expect(config('ai-chat-ui.routes.prefix'))->toBe('chat');
    expect(config('ai-chat-ui.queue.name'))->toBe('default');
});
