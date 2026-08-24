<?php

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Responses\Data\ToolCall;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Testbench\EchoAgent;
use Smwks\LaravelAiChatUi\Testbench\EchoToolAgent;

it('does nothing when context has no conversation id', function () {
    EchoAgent::fake(['hi']);

    (new EchoAgent)->prompt('hello');

    expect(ConversationEvent::count())->toBe(0);
});

it('captures llm.request and llm.response when context holds a conversation id', function () {
    EchoAgent::fake(['Echo: hello']);

    Context::add('ai-chat-ui.conversation_id', 'conv-abc');
    Context::add('ai-chat-ui.turn_id', 'turn-xyz');

    (new EchoAgent)->prompt('hello');

    $request = ConversationEvent::where('event_type', 'llm.request')->first();
    expect($request)->not->toBeNull();
    expect($request->conversation_id)->toBe('conv-abc');
    expect($request->turn_id)->toBe('turn-xyz');
    expect($request->payload['prompt'])->toBe('hello');

    $response = ConversationEvent::where('event_type', 'llm.response')->first();
    expect($response)->not->toBeNull();
    expect($response->payload['text'])->toBe('Echo: hello');
    expect($response->payload)->toHaveKeys(['usage', 'meta']);
});

it('captures tool.invoked with sql queries made during the tool call', function () {
    // FakeTextGateway::toStepResponse() checks `$response instanceof ToolCall` to decide
    // whether a faked step is a tool call (FinishReason::ToolCalls) or a final text answer.
    // So a raw `ToolCall` data object — not an `AgentResponse::fake()->withToolCallsAndResults()`
    // wrapper, which doesn't exist as a public API — is the correct shape for step 1, with a
    // plain string for the final step's answer. See vendor/laravel/ai/src/Gateway/FakeTextGateway.php.
    EchoToolAgent::fake([
        new ToolCall('call-1', 'NoopTool', ['value' => 'x']),
        'Done',
    ]);

    Context::add('ai-chat-ui.conversation_id', 'conv-tool');

    (new EchoToolAgent)->prompt('use the tool');

    $toolEvent = ConversationEvent::where('event_type', 'tool.invoked')->first();

    expect($toolEvent)->not->toBeNull();
    expect($toolEvent->payload['tool'])->toBe('NoopTool');
    expect($toolEvent->payload)->toHaveKeys(['parameters', 'result', 'duration_ms', 'sql_queries']);
});
