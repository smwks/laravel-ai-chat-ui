<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Models\ConversationTurn;

class FakeUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name'];
}

function makeUsersTable(): void
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}

it('auto-generates a ulid-shaped id and links to a conversation and participant', function () {
    makeUsersTable();
    $user = FakeUser::create(['name' => 'Ada']);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => FakeUser::class,
        'participant_id' => $user->id,
        'title' => 'Test conversation',
    ]);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => FakeUser::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    expect($turn->id)->not->toBeNull();
    expect(strlen($turn->id))->toBeGreaterThan(20);
    expect($turn->status)->toBe(ConversationTurnStatus::Pending);
    expect($turn->conversation->id)->toBe($conversation->id);
    expect($turn->participant->id)->toBe($user->id);
});

it('creates append-only events with an array payload and no updated_at', function () {
    $event = ConversationEvent::create([
        'conversation_id' => (string) Str::uuid7(),
        'turn_id' => null,
        'event_type' => 'llm.request',
        'payload' => ['model' => 'gpt-5'],
    ]);

    expect($event->id)->not->toBeNull();
    expect($event->payload)->toBe(['model' => 'gpt-5']);
    expect($event->getUpdatedAtColumn())->toBeNull();
});
