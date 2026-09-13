<?php

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;
use Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus;
use Smwks\LaravelAiKit\Chat\Jobs\ProcessChatMessage;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Turns\Models\ConversationTurn;
use Smwks\LaravelAiKit\Chat\Policies\ConversationPolicy;
use Smwks\LaravelAiKit\Testbench\EchoAgent;
use Smwks\LaravelAiKit\Testbench\EchoToolAgent;

class ChatConversationComponentTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

// Real ConversationPolicy::view() just delegates to sendMessage(), so there's no way
// to make view() allow while sendMessage() denies via ownership alone. This test-only
// policy hardcodes that split to isolate the mount()-time initial-message path's own
// sendMessage check from the view check that precedes it.
class ViewOnlyConversationPolicy extends ConversationPolicy
{
    public function view(AuthenticatableContract $user, Conversation $conversation): bool
    {
        return true;
    }

    public function sendMessage(AuthenticatableContract $user, Conversation $conversation): bool
    {
        return false;
    }
}

class NoThoughtsConversationPolicy extends ConversationPolicy
{
    public function viewThoughts(AuthenticatableContract $user, Conversation $conversation): bool
    {
        return false;
    }
}

function makeConversationFixture(): array
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }

    $user = ChatConversationComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'title' => 'Test conversation',
    ]);

    return [$user, $conversation];
}

it('sends the given initial message on mount and dispatches a job', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'initialMessage' => 'Hello there',
    ]);

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job) {
        return $job->message === 'Hello there';
    });

    expect(ConversationTurn::where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('dispatches the job with config(ai-chat-ui.agent) when no agent prop is given', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'initialMessage' => 'Hello there',
    ]);

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job) {
        return $job->agentClass === config('ai-chat-ui.agent');
    });
});

it('dispatches the job with the given agent prop instead of the config default', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'agent' => EchoToolAgent::class,
        'initialMessage' => 'Hello there',
    ]);

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job) {
        return $job->agentClass === EchoToolAgent::class;
    });
});

it('does not auto-send a turn when no initial message is given', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    Bus::assertNotDispatched(ProcessChatMessage::class);
    expect(ConversationTurn::where('conversation_id', $conversation->id)->count())->toBe(0);
});

it('rejects an initial message longer than 2000 characters', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'initialMessage' => str_repeat('a', 2001),
    ])->assertStatus(422);

    Bus::assertNotDispatched(ProcessChatMessage::class);
    expect(ConversationTurn::where('conversation_id', $conversation->id)->count())->toBe(0);
});

it('denies auto-sending an initial message when the user can view but not sendMessage', function () {
    [, $conversation] = makeConversationFixture();

    Gate::policy(Conversation::class, ViewOnlyConversationPolicy::class);

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'initialMessage' => 'Hello there',
    ])->assertForbidden();

    Bus::assertNotDispatched(ProcessChatMessage::class);
    expect(ConversationTurn::where('conversation_id', $conversation->id)->count())->toBe(0);
});

it('denies mounting a conversation you do not own', function () {
    [$owner, $conversation] = makeConversationFixture();

    $stranger = ChatConversationComponentTestUser::create(['name' => 'Stranger']);
    test()->actingAs($stranger);

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertForbidden();
});

it('denies sending a message on a conversation you do not own', function () {
    [$owner, $conversation] = makeConversationFixture();

    // Mount as the owner (allowed to view) so we can isolate and prove
    // sendMessage()'s own authorization check, independent of the
    // mount()-time view check covered by the previous test.
    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $stranger = ChatConversationComponentTestUser::create(['name' => 'Stranger']);
    test()->actingAs($stranger);

    $component->set('message', 'not mine')
        ->call('sendMessage')
        ->assertForbidden();
});

it('strips raw script tags and neutralizes unsafe link schemes when rendering assistant markdown', function () {
    [$user, $conversation] = makeConversationFixture();

    $conversation->messages()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'agent' => 'echo',
        'role' => 'assistant',
        'content' => 'Here you go <script>alert(1)</script> and [click me](javascript:alert(1))',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])->html();

    // The page legitimately ships its own <script> tags (json-viewer, scroll helper);
    // what must NOT survive is the injected payload becoming a live, executable tag
    // or an unsafe href.
    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->not->toContain('href="javascript:alert(1)"');
});

it('caches rendered markdown per message id instead of re-parsing on every render', function () {
    [$user, $conversation] = makeConversationFixture();

    $message = $conversation->messages()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'agent' => 'echo',
        'role' => 'assistant',
        'content' => 'first content',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertSee('first content');

    expect(\Illuminate\Support\Facades\Cache::has("ai-chat-ui.rendered-markdown.{$message->id}"))->toBeTrue();

    // Seed a sentinel directly into the cache for this message id — a fresh
    // render must reuse it rather than re-parsing $message->content, proving
    // the cache is actually consulted rather than just incidentally populated.
    \Illuminate\Support\Facades\Cache::forever("ai-chat-ui.rendered-markdown.{$message->id}", '<p>cached sentinel</p>');

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertSeeHtml('cached sentinel')
        ->assertDontSee('first content');
});

it('groups trace events under the assistant message from the same turn', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $assistantMessage = $conversation->messages()->where('role', 'assistant')->first();
    $grouped = $component->instance()->eventsByAssistantMessageId();

    expect($grouped->has($assistantMessage->id))->toBeTrue();
    expect($grouped->get($assistantMessage->id)->pluck('event_type')->all())->toContain('llm.request');
});

it('reorders a tool\'s own http.exchange to render after the tool.invoked box, not before it', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    // Written in the order they really happen: the tool's own HTTP call
    // completes (and is logged) during handle(), strictly before the
    // tool.invoked event that's only written once handle() returns.
    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'http.exchange',
        'payload' => ['source' => 'FetchPageTool', 'tool_invocation_id' => 'call-1'],
    ]);
    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'FetchPageTool', 'tool_invocation_id' => 'call-1'],
    ]);

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $assistantMessage = $conversation->messages()->where('role', 'assistant')->first();
    $types = $component->instance()->eventsByAssistantMessageId()->get($assistantMessage->id)->pluck('event_type')->all();

    $toolIndex = array_search('tool.invoked', $types, true);
    $httpIndex = array_search('http.exchange', $types, true);

    expect($toolIndex)->not->toBeFalse();
    expect($httpIndex)->toBeGreaterThan($toolIndex);
});

it('groups each http.exchange under the correct call when the same tool runs twice in one turn', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'http.exchange',
        'payload' => ['source' => 'FetchPageTool', 'tool_invocation_id' => 'call-1', 'url' => 'https://example.com/one'],
    ]);
    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'FetchPageTool', 'tool_invocation_id' => 'call-1'],
    ]);
    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'http.exchange',
        'payload' => ['source' => 'FetchPageTool', 'tool_invocation_id' => 'call-2', 'url' => 'https://example.com/two'],
    ]);
    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'FetchPageTool', 'tool_invocation_id' => 'call-2'],
    ]);

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $assistantMessage = $conversation->messages()->where('role', 'assistant')->first();
    $events = $component->instance()->eventsByAssistantMessageId()->get($assistantMessage->id)->values();

    // Each http.exchange should immediately follow the tool.invoked with the
    // matching tool_invocation_id — never the other call's.
    for ($i = 0; $i < $events->count(); $i++) {
        if ($events[$i]->event_type !== 'tool.invoked') {
            continue;
        }

        $next = $events->get($i + 1);
        expect($next->event_type)->toBe('http.exchange');
        expect($next->payload['tool_invocation_id'])->toBe($events[$i]->payload['tool_invocation_id']);
    }
});

it('does not leak a trace event belonging to another conversation via showDetails', function () {
    [, $conversationA] = makeConversationFixture();

    $owner = ChatConversationComponentTestUser::create(['name' => 'Other Owner']);
    $conversationB = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $owner::class,
        'participant_id' => $owner->id,
        'title' => 'Other conversation',
    ]);

    $turnB = ConversationTurn::create([
        'conversation_id' => $conversationB->id,
        'participant_type' => $owner::class,
        'participant_id' => $owner->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $eventB = ConversationEvent::create([
        'conversation_id' => $conversationB->id,
        'turn_id' => $turnB->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'secret prompt belonging to conversation B'],
    ]);

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversationA])
        ->call('showDetails', $eventB->id);

    expect($component->instance()->selectedEvent())->toBeNull();
});

it('renders the built-in tool partial when no tool_views mapping exists for that tool', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'weather', 'parameters' => ['city' => 'NYC'], 'result' => 'Sunny'],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('Tool: weather');
    expect($html)->toContain('SQL queries');
});

it('renders a consumer-registered tool view for a specific tool name', function () {
    [$user, $conversation] = makeConversationFixture();

    View::addLocation(__DIR__.'/../Fixtures/views');
    config(['ai-chat-ui.tool_views' => ['weather' => 'custom-tool-view']]);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'weather', 'parameters' => ['city' => 'NYC'], 'result' => 'Sunny'],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('CUSTOM WEATHER VIEW: weather');
    expect($html)->not->toContain('SQL queries');
});

it('shows a generic thinking status while streaming with no tool in flight', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->set('message', 'hi')
        ->call('sendMessage')
        ->assertSee('Thinking…');
});

it('shows the tool status message while an unmatched tool.invoking event is streaming', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->set('message', 'hi')
        ->call('sendMessage');

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $component->instance()->pendingTurnId,
        'event_type' => 'tool.invoking',
        'payload' => ['tool' => 'Weather', 'tool_invocation_id' => 'abc', 'status' => 'Checking the weather…'],
    ]);

    // currentStatus() is #[Computed], so it's memoized on the already-rendered
    // instance from the sendMessage() call above — a fresh interaction is needed
    // to force a real re-render against the event we just inserted.
    $component->call('checkTurnStatus')->assertSee('Checking the weather…');
});

it('falls back to a generic thinking status once the matching tool.invoked event lands', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->set('message', 'hi')
        ->call('sendMessage');

    $turnId = $component->instance()->pendingTurnId;

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turnId,
        'event_type' => 'tool.invoking',
        'payload' => ['tool' => 'Weather', 'tool_invocation_id' => 'abc', 'status' => 'Checking the weather…'],
    ]);

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turnId,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'Weather', 'tool_invocation_id' => 'abc', 'parameters' => [], 'result' => 'Sunny'],
    ]);

    $component->call('checkTurnStatus')
        ->assertDontSee('Checking the weather…')
        ->assertSee('Thinking…');
});

it('excludes tool.invoking events from the historical grouped trace list', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoking',
        'payload' => ['tool' => 'Weather', 'tool_invocation_id' => 'abc', 'status' => 'Checking the weather…'],
    ]);

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);
    $assistantMessage = $conversation->messages()->where('role', 'assistant')->first();
    $grouped = $component->instance()->eventsByAssistantMessageId();

    expect($grouped->get($assistantMessage->id)->pluck('event_type')->all())->not->toContain('tool.invoking');
});

it('renders one merged http-exchange detail panel with request and response bodies as plain JSON', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'http.exchange',
        'payload' => [
            'source' => 'provider',
            'method' => 'POST',
            'url' => 'https://proxy.example.com/chat/completions',
            'status' => 200,
            'duration_ms' => 842.5,
            'request' => ['headers' => ['content-type' => ['application/json']], 'body' => ['model' => 'claude-sonnet-5']],
            'response' => ['headers' => ['content-type' => ['application/json'], 'x-request-id' => ['abc-123']], 'body' => ['id' => 'resp-123']],
        ],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('HTTP Exchange');
    expect($html)->toContain('POST');
    expect($html)->toContain('https://proxy.example.com/chat/completions');
    expect($html)->toContain('842.5ms');
    expect($html)->toContain('claude-sonnet-5');
    expect($html)->toContain('resp-123');
    expect($html)->toContain('content-type');
    expect($html)->toContain('x-request-id');
    expect($html)->not->toContain('<json-viewer');
    expect($html)->not->toContain('>Raw</button>');
});

it('renders the new json tree viewer, not the old custom element, on the tool raw tab', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'weather', 'parameters' => ['city' => 'NYC'], 'result' => 'Sunny'],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('placeholder="Search…"');
    expect($html)->not->toContain('<json-viewer');
});

it('renders the new json tree viewer, not the old custom element, on the llm.request raw tab', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['provider' => 'openai', 'model' => 'gpt-5', 'prompt' => 'hi', 'messages' => [], 'tools' => []],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('placeholder="Search…"');
    expect($html)->not->toContain('<json-viewer');
});

it('renders the new json tree viewer, not the old custom element, on the llm.response raw tab', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.response',
        'payload' => ['text' => 'hello there', 'usage' => [], 'meta' => []],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('placeholder="Search…"');
    expect($html)->not->toContain('<json-viewer');
});

it('does not indent llm.request and llm.response, which bracket the whole prompt() call', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $request = new ConversationEvent(['event_type' => 'llm.request', 'payload' => []]);
    $response = new ConversationEvent(['event_type' => 'llm.response', 'payload' => []]);

    expect($component->instance()->eventIndentClass($request))->toBe('');
    expect($component->instance()->eventIndentClass($response))->toBe('');
});

it('indents a tool call and a provider-sourced http exchange one level under the llm bracket', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $tool = new ConversationEvent(['event_type' => 'tool.invoked', 'payload' => ['tool' => 'Weather']]);
    $providerExchange = new ConversationEvent(['event_type' => 'http.exchange', 'payload' => ['source' => 'provider']]);

    expect($component->instance()->eventIndentClass($tool))->toBe('ml-6');
    expect($component->instance()->eventIndentClass($providerExchange))->toBe('ml-6');
});

it('indents an http exchange made from inside a tool one level deeper than the tool call itself', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $toolExchange = new ConversationEvent(['event_type' => 'http.exchange', 'payload' => ['source' => 'Weather']]);

    expect($component->instance()->eventIndentClass($toolExchange))->toBe('ml-12');
});

it('treats a missing http source as a provider call for indentation, for events captured before this feature existed', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $legacyExchange = new ConversationEvent(['event_type' => 'http.exchange', 'payload' => []]);

    expect($component->instance()->eventIndentClass($legacyExchange))->toBe('ml-6');
});

it('shows the provider and model as the llm.request subtitle', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $event = new ConversationEvent([
        'event_type' => 'llm.request',
        'payload' => ['provider' => 'Laravel\\Ai\\Providers\\OpenAiCompatibleProvider', 'model' => 'claude-haiku-4-5'],
    ]);

    expect($component->instance()->eventSubtitle($event))->toBe('OpenAiCompatibleProvider · claude-haiku-4-5');
});

it('shows the method and host as the http.exchange subtitle', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $event = new ConversationEvent([
        'event_type' => 'http.exchange',
        'payload' => ['method' => 'POST', 'url' => 'https://proxy.knuckles.ziffmedia.cloud/chat/completions'],
    ]);

    expect($component->instance()->eventSubtitle($event))->toBe('POST proxy.knuckles.ziffmedia.cloud');
});

it('shows the tool name as the tool.invoked subtitle', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $event = new ConversationEvent(['event_type' => 'tool.invoked', 'payload' => ['tool' => 'FindTreasureTool']]);

    expect($component->instance()->eventSubtitle($event))->toBe('FindTreasureTool');
});

it('has no subtitle for event types that carry no extra summary info', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    $event = new ConversationEvent(['event_type' => 'llm.response', 'payload' => ['text' => 'hi']]);

    expect($component->instance()->eventSubtitle($event))->toBeNull();
});

it('does not blow up when llm.request/http.exchange payloads are missing the subtitle fields', function () {
    [, $conversation] = makeConversationFixture();

    $component = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation]);

    expect($component->instance()->eventSubtitle(new ConversationEvent(['event_type' => 'llm.request', 'payload' => []])))->toBeNull();
    expect($component->instance()->eventSubtitle(new ConversationEvent(['event_type' => 'http.exchange', 'payload' => []])))->toBeNull();
});

it('still uses the built-in tool partial for a different tool not covered by the mapping', function () {
    [$user, $conversation] = makeConversationFixture();

    View::addLocation(__DIR__.'/../Fixtures/views');
    config(['ai-chat-ui.tool_views' => ['weather' => 'custom-tool-view']]);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'tool.invoked',
        'payload' => ['tool' => 'send_email', 'parameters' => ['to' => 'a@b.com'], 'result' => 'sent'],
    ]);

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->call('showDetails', $event->id)
        ->html();

    expect($html)->toContain('Tool: send_email');
    expect($html)->not->toContain('CUSTOM WEATHER VIEW');
});

it('shows the show-thoughts disclosure by default for a completed turn', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertSee('show thoughts');
});

it('exposes data-ai-chat-ui hooks for styling', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertSeeHtml('data-ai-chat-ui="root"')
        ->assertSeeHtml('data-ai-chat-ui="header"')
        ->assertSeeHtml('data-ai-chat-ui="thread"')
        ->assertSeeHtml('data-ai-chat-ui="composer"')
        ->assertSeeHtml('data-ai-chat-ui="message"')
        ->assertSeeHtml('data-ai-chat-ui-role="user"')
        ->assertSeeHtml('data-ai-chat-ui-role="assistant"')
        ->assertSeeHtml('data-ai-chat-ui="reply-body"')
        ->assertSeeHtml('data-ai-chat-ui="thoughts-toggle"')
        ->assertSeeHtml('data-ai-chat-ui="thought-event"');
});

it('hides the show-thoughts disclosure when the showThoughts prop is false', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'showThoughts' => false,
    ])->assertDontSee('show thoughts');
});

it('hides the show-thoughts disclosure when the viewThoughts policy denies it, even with the prop true', function () {
    [$user, $conversation] = makeConversationFixture();

    Gate::policy(Conversation::class, NoThoughtsConversationPolicy::class);

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi', EchoAgent::class))->handle();

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'showThoughts' => true,
    ])->assertDontSee('show thoughts');
});

it('forbids showDetails() when thoughts are not allowed, not just hiding the button', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'showThoughts' => false,
    ])->call('showDetails', $event->id)->assertForbidden();
});

it('shows the conversation id and click-to-copy affordance by default', function () {
    [, $conversation] = makeConversationFixture();

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertSee($conversation->id);
});

it('hides the conversation id when the showIds prop is false', function () {
    [, $conversation] = makeConversationFixture();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'showIds' => false,
    ])->assertDontSee($conversation->id);
});

it('hides an event id in the detail panel when the showIds prop is false', function () {
    [$user, $conversation] = makeConversationFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Complete,
    ]);

    $event = ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'turn_id' => $turn->id,
        'event_type' => 'llm.request',
        'payload' => ['prompt' => 'hi'],
    ]);

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'showIds' => false,
    ])->call('showDetails', $event->id)->assertDontSee($event->id);
});

it('shows its own title header by default', function () {
    [, $conversation] = makeConversationFixture();

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->assertSee($conversation->title);
});

it('hides the title header and conversation id when showHeader is false', function () {
    [, $conversation] = makeConversationFixture();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'showHeader' => false,
    ])
        ->assertDontSee($conversation->title)
        ->assertDontSee($conversation->id);
});

it('uses a custom containerClass when given', function () {
    [, $conversation] = makeConversationFixture();

    Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'containerClass' => 'my-custom-wrapper',
    ])
        ->assertSeeHtml('my-custom-wrapper')
        ->assertDontSeeHtml('max-w-3xl');
});

it('does not make the thread its own scroll container by default', function () {
    [, $conversation] = makeConversationFixture();

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])->html();

    expect($html)->not->toContain('min-h-0 flex-1 space-y-4 overflow-y-auto');
    expect($html)->not->toContain('mx-auto flex h-full max-w-3xl flex-col p-6');
});

it('makes the thread scroll and pins the composer when fillHeight is true', function () {
    [, $conversation] = makeConversationFixture();

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'fillHeight' => true,
    ])->html();

    expect($html)->toContain('min-h-0 flex-1 space-y-4 overflow-y-auto');
    expect($html)->toContain('mx-auto flex h-full max-w-3xl flex-col p-6');
});

it('teleports the details panel to body with a default z-index of 50', function () {
    [, $conversation] = makeConversationFixture();

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])->html();

    expect($html)->toContain('x-teleport="body"');
    expect($html)->toContain('style="z-index: 50"');
});

it('uses a custom detailsZIndex when given', function () {
    [, $conversation] = makeConversationFixture();

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', [
        'conversation' => $conversation,
        'detailsZIndex' => 9999,
    ])->html();

    expect($html)->toContain('style="z-index: 9999"');
});

it('renders an auto-growing textarea composer wired to send on Enter, not Shift+Enter', function () {
    [, $conversation] = makeConversationFixture();

    $html = Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])->html();

    expect($html)->toContain('<textarea');
    expect($html)->not->toContain('<input');
    expect($html)->toContain('wire:model="message"');
    expect($html)->toContain('if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage() }');
});

it('still sends the message when sendMessage is called, regardless of composer markup', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-chat-ui::components.chat.conversation', ['conversation' => $conversation])
        ->set('message', 'hello via textarea')
        ->call('sendMessage');

    Bus::assertDispatched(ProcessChatMessage::class, fn (ProcessChatMessage $job) => $job->message === 'hello via textarea');
});
