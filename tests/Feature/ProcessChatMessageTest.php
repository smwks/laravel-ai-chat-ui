<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus;
use Smwks\LaravelAiKit\Chat\Jobs\ProcessChatMessage;
use Smwks\LaravelAiKit\Turns\Models\ConversationTurn;
use Smwks\LaravelAiKit\Testbench\EchoAgent;
use Smwks\LaravelAiKit\Testbench\EchoToolAgent;

class ProcessChatMessageTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name'];

    public $timestamps = false;
}

function makeTurnFixture(): array
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
    }

    $user = ProcessChatMessageTestUser::create(['name' => 'Ada']);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => ProcessChatMessageTestUser::class,
        'participant_id' => $user->id,
        'title' => 'Test conversation',
    ]);

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => ProcessChatMessageTestUser::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    return [$conversation, $turn];
}

it('drives a turn from pending to complete and persists the assistant message', function () {
    [$conversation, $turn] = makeTurnFixture();

    EchoAgent::fake(['Echo: hi there']);

    (new ProcessChatMessage($turn, 'hi there', EchoAgent::class))->handle();

    $turn->refresh();
    expect($turn->status)->toBe(ConversationTurnStatus::Complete);

    $conversation->refresh();
    $assistantMessage = $conversation->messages()->where('role', 'assistant')->latest('id')->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->content)->toBe('Echo: hi there');
});

it('marks the turn failed and rethrows when the agent throws', function () {
    [, $turn] = makeTurnFixture();

    EchoAgent::fake(function () {
        throw new RuntimeException('provider unavailable');
    });

    expect(fn () => (new ProcessChatMessage($turn, 'hi there', EchoAgent::class))->handle())
        ->toThrow(RuntimeException::class, 'provider unavailable');

    $turn->refresh();
    expect($turn->status)->toBe(ConversationTurnStatus::Failed);
});

it('prompts the given agentClass rather than any other registered agent', function () {
    [$conversation, $turn] = makeTurnFixture();

    EchoAgent::fake(['should never be used']);
    EchoToolAgent::fake(['Echo tool agent replied']);

    (new ProcessChatMessage($turn, 'hi there', EchoToolAgent::class))->handle();

    EchoAgent::assertNeverPrompted();
    EchoToolAgent::assertPrompted('hi there');

    $conversation->refresh();
    $assistantMessage = $conversation->messages()->where('role', 'assistant')->latest('id')->first();

    expect($assistantMessage->content)->toBe('Echo tool agent replied');
});
