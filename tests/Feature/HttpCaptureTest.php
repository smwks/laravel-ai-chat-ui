<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;

it('captures request and response events only while context holds a conversation id', function () {
    Http::fake(['https://example.com/*' => Http::response(['ok' => true], 200, ['X-Test' => 'yes'])]);

    // No context set — nothing should be captured.
    Http::withHeaders(['Authorization' => 'Bearer secret'])->get('https://example.com/no-context');

    expect(ConversationEvent::count())->toBe(0);

    Context::add('ai-chat-ui.conversation_id', 'conv-123');
    Context::add('ai-chat-ui.turn_id', 'turn-456');

    Http::withHeaders(['Authorization' => 'Bearer secret'])->get('https://example.com/with-context');

    $events = ConversationEvent::orderBy('event_type')->get();

    expect($events)->toHaveCount(2);

    $request = $events->firstWhere('event_type', 'http.request');
    expect($request->conversation_id)->toBe('conv-123');
    expect($request->turn_id)->toBe('turn-456');
    expect($request->payload['method'])->toBe('GET');
    expect($request->payload['headers'])->not->toHaveKey('authorization');

    $response = $events->firstWhere('event_type', 'http.response');
    expect($response->payload['status'])->toBe(200);
    expect($response->payload['body'])->toBe(['ok' => true]);
});
