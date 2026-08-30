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

it('shows its own heading by default', function () {
    actingAsChatUser();

    Livewire::test('ai-chat-ui::components.chat.new')->assertSee('New conversation');
});

it('hides the heading when showHeader is false', function () {
    actingAsChatUser();

    Livewire::test('ai-chat-ui::components.chat.new', ['showHeader' => false])
        ->assertDontSee('New conversation');
});

it('uses a custom containerClass when given', function () {
    actingAsChatUser();

    Livewire::test('ai-chat-ui::components.chat.new', ['containerClass' => 'my-custom-wrapper'])
        ->assertSeeHtml('my-custom-wrapper')
        ->assertDontSeeHtml('max-w-2xl');
});

it('exposes data-ai-chat-ui hooks for styling', function () {
    actingAsChatUser();

    Livewire::test('ai-chat-ui::components.chat.new')
        ->assertSeeHtml('data-ai-chat-ui="root"')
        ->assertSeeHtml('data-ai-chat-ui="header"')
        ->assertSeeHtml('data-ai-chat-ui="composer"');
});

it('creates a conversation and dispatches ai-chat-ui-conversation-started', function () {
    $user = actingAsChatUser();

    $component = Livewire::test('ai-chat-ui::components.chat.new')
        ->set('message', 'What is the weather like?')
        ->call('sendMessage');

    $conversation = Conversation::first();

    expect($conversation)->not->toBeNull();
    expect($conversation->participant_type)->toBe($user::class);
    expect((string) $conversation->participant_id)->toBe((string) $user->id);
    expect($conversation->title)->toBe('What is the weather like?');

    $component->assertDispatched(
        'ai-chat-ui-conversation-started',
        conversationId: $conversation->id,
        message: 'What is the weather like?'
    );
});
