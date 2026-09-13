<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Testbench\EchoAgent;
use Smwks\LaravelAiKit\Testbench\EchoStatusToolAgent;
use Smwks\LaravelAiKit\Testbench\EchoToolAgent;

it('does nothing when context has no conversation id', function () {
    EchoAgent::fake(['hi']);

    (new EchoAgent)->prompt('hello');

    expect(ConversationEvent::count())->toBe(0);
});

it('captures llm.request and llm.response when context holds a conversation id', function () {
    EchoAgent::fake(['Echo: hello']);

    Context::add('ai-kit.conversation_id', 'conv-abc');
    Context::add('ai-kit.turn_id', 'turn-xyz');

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

    Context::add('ai-kit.conversation_id', 'conv-tool');

    (new EchoToolAgent)->prompt('use the tool');

    $toolEvent = ConversationEvent::where('event_type', 'tool.invoked')->first();

    expect($toolEvent)->not->toBeNull();
    expect($toolEvent->payload['tool'])->toBe('NoopTool');
    expect($toolEvent->payload)->toHaveKeys(['parameters', 'result', 'duration_ms', 'sql_queries']);
});

it('captures tool.invoking with a description-based status when the tool has no custom status message', function () {
    EchoToolAgent::fake([
        new ToolCall('call-1', 'NoopTool', ['value' => 'x']),
        'Done',
    ]);

    Context::add('ai-kit.conversation_id', 'conv-invoking');

    (new EchoToolAgent)->prompt('use the tool');

    $invoking = ConversationEvent::where('event_type', 'tool.invoking')->first();

    expect($invoking)->not->toBeNull();
    expect($invoking->payload['tool'])->toBe('NoopTool');
    expect($invoking->payload['status'])->toBe('A no-op tool used only in package tests.');
    expect($invoking->payload)->toHaveKey('tool_invocation_id');
});

it('captures tool.invoking with a custom status message when the tool implements HasStatusMessage', function () {
    EchoStatusToolAgent::fake([
        new ToolCall('call-1', 'StatusMessageTool', ['value' => 'NYC']),
        'Done',
    ]);

    Context::add('ai-kit.conversation_id', 'conv-status');

    (new EchoStatusToolAgent)->prompt('use the tool');

    $invoking = ConversationEvent::where('event_type', 'tool.invoking')->first();

    expect($invoking->payload['status'])->toBe('Looking up NYC…');
});

it('correlates tool.invoking and tool.invoked via the same tool_invocation_id', function () {
    EchoToolAgent::fake([
        new ToolCall('call-1', 'NoopTool', ['value' => 'x']),
        'Done',
    ]);

    Context::add('ai-kit.conversation_id', 'conv-correlate');

    (new EchoToolAgent)->prompt('use the tool');

    $invoking = ConversationEvent::where('event_type', 'tool.invoking')->first();
    $invoked = ConversationEvent::where('event_type', 'tool.invoked')->first();

    expect($invoking->payload['tool_invocation_id'])->toBe($invoked->payload['tool_invocation_id']);
});

it('captures tool.approval_requested with each pending call when context holds a conversation id', function () {
    Context::add('ai-kit.conversation_id', 'conv-approval');
    Context::add('ai-kit.turn_id', 'turn-approval');

    Event::dispatch(new ToolApprovalRequested(
        'inv-1',
        new EchoToolAgent,
        Collection::make([new PendingApproval('call-1', 'NoopTool', ['value' => 'x'], 'Confirm this.')]),
        'conv-approval',
    ));

    $event = ConversationEvent::where('event_type', 'tool.approval_requested')->first();

    expect($event)->not->toBeNull()
        ->and($event->conversation_id)->toBe('conv-approval')
        ->and($event->turn_id)->toBe('turn-approval')
        ->and($event->payload['approvals'][0])->toMatchArray([
            'id' => 'call-1',
            'tool' => 'NoopTool',
            'reason' => 'Confirm this.',
        ]);
});

it('captures tool.approval_resolved with the resolved results', function () {
    Context::add('ai-kit.conversation_id', 'conv-resolved');

    Event::dispatch(new ToolApprovalResolved(
        'inv-1',
        new EchoToolAgent,
        Collection::make([
            new ToolResult('call-1', 'NoopTool', ['value' => 'x'], 'noop-result'),
            new ToolResult('call-2', 'NoopTool', ['value' => 'y'], null, denied: true),
        ]),
        'conv-resolved',
    ));

    $event = ConversationEvent::where('event_type', 'tool.approval_resolved')->first();

    expect($event->payload['results'])->toHaveCount(2)
        ->and($event->payload['results'][0]['denied'])->toBeFalse()
        ->and($event->payload['results'][1]['denied'])->toBeTrue();
});

it('writes nothing for approval events when context has no conversation id', function () {
    Event::dispatch(new ToolApprovalRequested('inv-1', new EchoToolAgent, Collection::make([
        new PendingApproval('call-1', 'NoopTool', [], null),
    ])));

    expect(ConversationEvent::count())->toBe(0);
});
