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
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;
use Smwks\LaravelAiChatUi\Jobs\ProcessChatMessage;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Models\ConversationTurn;
use Smwks\LaravelAiChatUi\Policies\ConversationPolicy;
use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

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

it('groups trace events under the assistant message from the same turn', function () {
    [$user, $conversation] = makeConversationFixture();

    EchoAgent::fake(['Echo: hi']);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    (new ProcessChatMessage($turn, 'hi'))->handle();

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

    (new ProcessChatMessage($turn, 'hi'))->handle();

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
            'request' => ['headers' => [], 'body' => ['model' => 'claude-sonnet-5']],
            'response' => ['headers' => [], 'body' => ['id' => 'resp-123']],
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
