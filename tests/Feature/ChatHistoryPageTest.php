<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;

class ChatHistoryPageTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

it('lists conversations and computes token/model stats from llm events', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    $user = ChatHistoryPageTestUser::create(['name' => 'Ada']);
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

    $component = Livewire::test('ai-chat-ui::pages.chat.history');

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

    $user = ChatHistoryPageTestUser::create(['name' => 'Ada']);
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

    Livewire::test('ai-chat-ui::pages.chat.history')
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

    $owner = ChatHistoryPageTestUser::create(['name' => 'Ada']);
    $stranger = ChatHistoryPageTestUser::create(['name' => 'Grace']);

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

    Livewire::test('ai-chat-ui::pages.chat.history')
        ->assertSee('Owner conversation')
        ->assertDontSee('Stranger conversation');
});

it('denies statsFor for a conversation you do not own', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $owner = ChatHistoryPageTestUser::create(['name' => 'Ada']);
    $stranger = ChatHistoryPageTestUser::create(['name' => 'Grace']);

    $strangerConversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $stranger::class,
        'participant_id' => $stranger->id,
        'title' => 'Stranger conversation',
    ]);

    test()->actingAs($owner);

    Livewire::test('ai-chat-ui::pages.chat.history')
        ->call('statsFor', $strangerConversation->id)
        ->assertForbidden();
});
