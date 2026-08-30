<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;

class ChatHistoryComponentTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

it('hides the heading when showHeader is false', function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    $user = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->assertSee('Conversation history');

    Livewire::test('ai-chat-ui::components.chat.history', ['showHeader' => false])
        ->assertDontSee('Conversation history');
});

it('uses a custom containerClass when given', function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    $user = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    Livewire::test('ai-chat-ui::components.chat.history', ['containerClass' => 'my-custom-wrapper'])
        ->assertSeeHtml('my-custom-wrapper')
        ->assertDontSeeHtml('w-full p-6');
});

it('exposes data-ai-chat-ui hooks for styling', function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    $user = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->assertSeeHtml('data-ai-chat-ui="root"')
        ->assertSeeHtml('data-ai-chat-ui="header"');
});

it('lists conversations and computes token/model stats from llm events', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    $user = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'title' => 'Weather question',
    ]);

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'event_type' => 'llm.request',
        'payload' => ['model' => 'gpt-5-mini'],
    ]);

    ConversationEvent::create([
        'conversation_id' => $conversation->id,
        'event_type' => 'llm.response',
        'payload' => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
    ]);

    $component = Livewire::test('ai-chat-ui::components.chat.history');

    $component->assertSee('Weather question');

    $stats = $component->instance()->statsFor($conversation);

    expect($stats['model'])->toBe('gpt-5-mini');
    expect($stats['total_tokens'])->toBe(15);
});

it('filters conversations by search term', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $user = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'title' => 'Weather question',
    ]);

    Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'title' => 'Sports scores',
    ]);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->set('search', 'weather')
        ->assertSee('Weather question')
        ->assertDontSee('Sports scores');
});

it('only lists conversations belonging to the authenticated user', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $owner = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    $stranger = ChatHistoryComponentTestUser::create(['name' => 'Grace']);

    Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $owner::class,
        'participant_id' => $owner->id,
        'title' => 'Owner conversation',
    ]);

    Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $stranger::class,
        'participant_id' => $stranger->id,
        'title' => 'Stranger conversation',
    ]);

    test()->actingAs($owner);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->assertSee('Owner conversation')
        ->assertDontSee('Stranger conversation');
});

it('denies statsFor for a conversation you do not own', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $owner = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    $stranger = ChatHistoryComponentTestUser::create(['name' => 'Grace']);

    $strangerConversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $stranger::class,
        'participant_id' => $stranger->id,
        'title' => 'Stranger conversation',
    ]);

    test()->actingAs($owner);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->call('statsFor', $strangerConversation->id)
        ->assertForbidden();
});

it('dispatches ai-chat-ui-conversation-selected when a conversation is selected', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $user = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'title' => 'Weather question',
    ]);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->call('selectConversation', $conversation->id)
        ->assertDispatched('ai-chat-ui-conversation-selected', conversationId: $conversation->id);
});

it('denies selecting a conversation you do not own', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $owner = ChatHistoryComponentTestUser::create(['name' => 'Ada']);
    $stranger = ChatHistoryComponentTestUser::create(['name' => 'Grace']);

    $strangerConversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $stranger::class,
        'participant_id' => $stranger->id,
        'title' => 'Stranger conversation',
    ]);

    test()->actingAs($owner);

    Livewire::test('ai-chat-ui::components.chat.history')
        ->call('selectConversation', $strangerConversation->id)
        ->assertForbidden();
});
