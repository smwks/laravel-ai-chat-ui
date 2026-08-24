<?php

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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
