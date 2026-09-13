<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ToolCall;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Testbench\EchoHttpToolAgent;

it('captures one http.exchange event only while context holds a conversation id', function () {
    Http::fake(['https://example.com/*' => Http::response(['ok' => true], 200, ['X-Test' => 'yes'])]);

    // No context set — nothing should be captured.
    Http::withHeaders(['Authorization' => 'Bearer secret'])->get('https://example.com/no-context');

    expect(ConversationEvent::count())->toBe(0);

    Context::add('ai-chat-ui.conversation_id', 'conv-123');
    Context::add('ai-chat-ui.turn_id', 'turn-456');

    Http::withHeaders(['Authorization' => 'Bearer secret'])->get('https://example.com/with-context');

    $events = ConversationEvent::where('event_type', 'http.exchange')->get();

    expect($events)->toHaveCount(1);

    $exchange = $events->first();
    expect($exchange->conversation_id)->toBe('conv-123');
    expect($exchange->turn_id)->toBe('turn-456');
    expect($exchange->payload['method'])->toBe('GET');
    expect($exchange->payload['source'])->toBe('provider');
    expect($exchange->payload['status'])->toBe(200);
    expect($exchange->payload['duration_ms'])->toBeFloat();
    expect($exchange->payload['request']['headers'])->not->toHaveKey('authorization');
    expect($exchange->payload['response']['body'])->toBe(['ok' => true]);
});

it('tags an http exchange made from inside a tool with that tool as its source', function () {
    Http::fake(['https://example.com/*' => Http::response('tool response body', 200)]);

    EchoHttpToolAgent::fake([
        new ToolCall('call-1', 'HttpCallingTool', ['value' => 'x']),
        'Done',
    ]);

    Context::add('ai-chat-ui.conversation_id', 'conv-tool-http');

    (new EchoHttpToolAgent)->prompt('use the tool');

    $exchange = ConversationEvent::where('event_type', 'http.exchange')->first();

    expect($exchange->payload['source'])->toBe('HttpCallingTool');
});

it('tags a tool-sourced http exchange with the same tool_invocation_id as its tool.invoked event', function () {
    Http::fake(['https://example.com/*' => Http::response('tool response body', 200)]);

    EchoHttpToolAgent::fake([
        new ToolCall('call-1', 'HttpCallingTool', ['value' => 'x']),
        'Done',
    ]);

    Context::add('ai-chat-ui.conversation_id', 'conv-tool-http-invocation-id');

    (new EchoHttpToolAgent)->prompt('use the tool');

    $exchange = ConversationEvent::where('event_type', 'http.exchange')->first();
    $invoked = ConversationEvent::where('event_type', 'tool.invoked')->first();

    expect($exchange->payload['tool_invocation_id'])->not->toBeNull();
    expect($exchange->payload['tool_invocation_id'])->toBe($invoked->payload['tool_invocation_id']);
});

it('does not leak a tool source onto an http exchange made after the tool call finishes', function () {
    Http::fake(['https://example.com/*' => Http::response('tool response body', 200)]);

    EchoHttpToolAgent::fake([
        new ToolCall('call-1', 'HttpCallingTool', ['value' => 'x']),
        'Done',
    ]);

    Context::add('ai-chat-ui.conversation_id', 'conv-tool-http-cleanup');

    (new EchoHttpToolAgent)->prompt('use the tool');

    Http::withHeaders([])->get('https://example.com/after-tool-call');

    $exchanges = ConversationEvent::where('event_type', 'http.exchange')->orderBy('created_at')->get();

    expect($exchanges->last()->payload['source'])->toBe('provider');
});
