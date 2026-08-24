<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;

class ChatNewPageTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

function actingAsChatUser(): ChatNewPageTestUser
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    $user = ChatNewPageTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    return $user;
}

it('renders the chat.new page', function () {
    actingAsChatUser();

    $this->get('/chat')->assertSeeLivewire('ai-chat-ui::pages.chat.new');
});

it('creates a conversation and redirects to it, stashing the first message', function () {
    $user = actingAsChatUser();

    Livewire::test('ai-chat-ui::pages.chat.new')
        ->set('message', 'What is the weather like?')
        ->call('sendMessage')
        ->assertRedirect();

    $conversation = Conversation::first();

    expect($conversation)->not->toBeNull();
    expect($conversation->participant_type)->toBe($user::class);
    expect((string) $conversation->participant_id)->toBe((string) $user->id);
    expect($conversation->title)->toBe('What is the weather like?');
    expect(session('ai-chat-ui.initial_message'))->toBe('What is the weather like?');
});
