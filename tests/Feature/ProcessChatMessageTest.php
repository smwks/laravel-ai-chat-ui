<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;
use Smwks\LaravelAiChatUi\Jobs\ProcessChatMessage;
use Smwks\LaravelAiChatUi\Models\ConversationTurn;
use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

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

    (new ProcessChatMessage($turn, 'hi there'))->handle();

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

    expect(fn () => (new ProcessChatMessage($turn, 'hi there'))->handle())
        ->toThrow(RuntimeException::class, 'provider unavailable');

    $turn->refresh();
    expect($turn->status)->toBe(ConversationTurnStatus::Failed);
});
