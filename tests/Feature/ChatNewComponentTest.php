<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;

class ChatNewComponentTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

function actingAsChatUser(): ChatNewComponentTestUser
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    $user = ChatNewComponentTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    return $user;
}

it('renders the chat.new component', function () {
    actingAsChatUser();

    Livewire::test('ai-chat-ui::components.chat.new')->assertOk();
});

it('creates a conversation and dispatches ai-chat-ui-conversation-started', function () {
    $user = actingAsChatUser();

    Livewire::test('ai-chat-ui::components.chat.new')
        ->set('message', 'What is the weather like?')
        ->call('sendMessage')
        ->assertDispatched('ai-chat-ui-conversation-started', message: 'What is the weather like?');

    $conversation = Conversation::first();

    expect($conversation)->not->toBeNull();
    expect($conversation->participant_type)->toBe($user::class);
    expect((string) $conversation->participant_id)->toBe((string) $user->id);
    expect($conversation->title)->toBe('What is the weather like?');
});
