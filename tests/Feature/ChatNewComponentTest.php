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

    Livewire::test('ai-kit::components.chat.new')->assertOk();
});

it('shows its own heading by default', function () {
    actingAsChatUser();

    Livewire::test('ai-kit::components.chat.new')->assertSee('New conversation');
});

it('hides the heading when showHeader is false', function () {
    actingAsChatUser();

    Livewire::test('ai-kit::components.chat.new', ['showHeader' => false])
        ->assertDontSee('New conversation');
});

it('uses a custom containerClass when given', function () {
    actingAsChatUser();

    Livewire::test('ai-kit::components.chat.new', ['containerClass' => 'my-custom-wrapper'])
        ->assertSeeHtml('my-custom-wrapper')
        ->assertDontSeeHtml('max-w-2xl');
});

it('exposes data-ai-kit hooks for styling', function () {
    actingAsChatUser();

    Livewire::test('ai-kit::components.chat.new')
        ->assertSeeHtml('data-ai-kit="root"')
        ->assertSeeHtml('data-ai-kit="header"')
        ->assertSeeHtml('data-ai-kit="composer"');
});

it('creates a conversation and dispatches ai-kit-conversation-started', function () {
    $user = actingAsChatUser();

    $component = Livewire::test('ai-kit::components.chat.new')
        ->set('message', 'What is the weather like?')
        ->call('sendMessage');

    $conversation = Conversation::first();

    expect($conversation)->not->toBeNull();
    expect($conversation->participant_type)->toBe($user::class);
    expect((string) $conversation->participant_id)->toBe((string) $user->id);
    expect($conversation->title)->toBe('What is the weather like?');

    $component->assertDispatched(
        'ai-kit-conversation-started',
        conversationId: $conversation->id,
        message: 'What is the weather like?'
    );
});
