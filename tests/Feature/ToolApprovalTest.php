<?php

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Livewire\Livewire;
use Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus;
use Smwks\LaravelAiKit\Chat\Jobs\ProcessChatMessage;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Turns\Models\ConversationTurn;
use Smwks\LaravelAiKit\Chat\Policies\ConversationPolicy;
use Smwks\LaravelAiKit\Testbench\ApprovalToolAgent;
use Smwks\LaravelAiKit\Testbench\EchoAgent;

class ToolApprovalTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class ToolApprovalViewOnlyPolicy extends ConversationPolicy
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

function approvalFixture(): array
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
    }

    $user = ToolApprovalTestUser::create(['name' => 'Ada']);
    test()->actingAs($user);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'title' => 'Approval conversation',
    ]);

    return [$user, $conversation];
}

/**
 * Persist the DB state of a turn paused for approval: a user message, an
 * assistant row carrying the pending tool calls + approval_state, and an
 * AWAITING_APPROVAL turn.
 *
 * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>, reason: string}>  $calls
 */
function pauseTurn(Conversation $conversation, object $user, array $calls): ConversationMessage
{
    $now = now();

    ConversationMessage::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'agent' => ApprovalToolAgent::class,
        'role' => 'user',
        'content' => 'please do the thing',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $assistant = ConversationMessage::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'agent' => ApprovalToolAgent::class,
        'role' => 'assistant',
        'content' => 'I need to run a tool first.',
        'attachments' => [],
        'tool_calls' => collect($calls)->map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'arguments' => $c['arguments'],
        ])->all(),
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => ['pending' => collect($calls)->mapWithKeys(fn ($c) => [$c['id'] => $c['reason']])->all()],
        'created_at' => $now->copy()->addSecond(),
        'updated_at' => $now->copy()->addSecond(),
    ]);

    ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::AwaitingApproval,
    ]);

    return $assistant;
}

it('ends the turn awaiting approval and records a trace event when the agent pauses', function () {
    [$user, $conversation] = approvalFixture();

    $turn = ConversationTurn::create([
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'status' => ConversationTurnStatus::Pending,
    ]);

    $paused = (new AgentResponse('inv-1', '', new Usage, new Meta('openai', 'gpt-x')))
        ->withToolCallsAndResults(
            collect([new ToolCall('call_1', 'ApprovalTool', ['value' => 'x'])]),
            collect(),
        )
        ->withPendingApprovals(collect([
            new PendingApproval('call_1', 'ApprovalTool', ['value' => 'x'], 'Confirm this.'),
        ]));

    ApprovalToolAgent::fake([$paused]);

    (new ProcessChatMessage($turn, 'do the thing', ApprovalToolAgent::class))->handle();

    expect($turn->fresh()->status)->toBe(ConversationTurnStatus::AwaitingApproval);

    $event = ConversationEvent::where('conversation_id', $conversation->id)
        ->where('event_type', 'tool.approval_requested')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->payload['approvals'][0]['id'])->toBe('call_1')
        ->and($event->payload['approvals'][0]['reason'])->toBe('Confirm this.');

    $assistant = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
    expect($assistant->approval_state['pending'])->toHaveKey('call_1');
});

it('renders an approve/reject prompt for a paused turn', function () {
    [$user, $conversation] = approvalFixture();

    pauseTurn($conversation, $user, [[
        'id' => 'call_1',
        'name' => 'UpdateMeetingTool',
        'arguments' => ['meeting_id' => 'abc', 'meeting_at' => '2026-09-04 18:00'],
        'reason' => 'This changes a meeting record.',
    ]]);

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->assertSee('UpdateMeetingTool')
        ->assertSee('This changes a meeting record.')
        ->assertSee('meeting_at')
        ->assertSeeHtml('data-ai-kit="approval-request"')
        ->assertSee('Approve')
        ->assertSee('Reject')
        ->assertSee('Resolve the pending approval to continue');
});

it('approving a call settles the paused turn and dispatches a resume with the decision', function () {
    [$user, $conversation] = approvalFixture();

    pauseTurn($conversation, $user, [[
        'id' => 'call_1', 'name' => 'UpdateMeetingTool', 'arguments' => ['x' => 1], 'reason' => 'r',
    ]]);

    Bus::fake();

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->call('approvePendingCall', 'call_1');

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job) {
        return $job->message instanceof Decisions
            && $job->message->get('call_1')?->isApproved() === true;
    });

    expect(ConversationTurn::where('conversation_id', $conversation->id)->where('status', ConversationTurnStatus::AwaitingApproval)->count())->toBe(0)
        ->and(ConversationTurn::where('conversation_id', $conversation->id)->where('status', ConversationTurnStatus::Pending)->count())->toBe(1);
});

it('rejecting every pending call dispatches a resume rejecting them', function () {
    [$user, $conversation] = approvalFixture();

    pauseTurn($conversation, $user, [
        ['id' => 'call_1', 'name' => 'ToolA', 'arguments' => [], 'reason' => 'a'],
        ['id' => 'call_2', 'name' => 'ToolB', 'arguments' => [], 'reason' => 'b'],
    ]);

    Bus::fake();

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->assertSee('Reject all')
        ->call('rejectAllPending');

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job) {
        return $job->message instanceof Decisions
            && $job->message->get('call_1')?->isRejected() === true
            && $job->message->get('call_2')?->isRejected() === true;
    });
});

it('does not resolve until every pending call has a decision', function () {
    [$user, $conversation] = approvalFixture();

    pauseTurn($conversation, $user, [
        ['id' => 'call_1', 'name' => 'ToolA', 'arguments' => [], 'reason' => 'a'],
        ['id' => 'call_2', 'name' => 'ToolB', 'arguments' => [], 'reason' => 'b'],
    ]);

    Bus::fake();

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->call('approvePendingCall', 'call_1');

    Bus::assertNotDispatched(ProcessChatMessage::class);
    expect(ConversationTurn::where('conversation_id', $conversation->id)->where('status', ConversationTurnStatus::AwaitingApproval)->count())->toBe(1);
});

it('hides the prompt once the pending calls have been resolved on a later row', function () {
    [$user, $conversation] = approvalFixture();

    $assistant = pauseTurn($conversation, $user, [[
        'id' => 'call_1', 'name' => 'ToolA', 'arguments' => [], 'reason' => 'a',
    ]]);

    ConversationMessage::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'participant_type' => $user::class,
        'participant_id' => $user->id,
        'agent' => ApprovalToolAgent::class,
        'role' => 'assistant',
        'content' => 'Done.',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [['id' => 'call_1', 'name' => 'ToolA', 'arguments' => [], 'result' => 'ok']],
        'usage' => [],
        'meta' => [],
        'approval_state' => null,
        'created_at' => $assistant->created_at->copy()->addSecond(),
        'updated_at' => $assistant->created_at->copy()->addSecond(),
    ]);

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->assertDontSeeHtml('data-ai-kit="approval-request"')
        ->assertSee('Done.');
});

it('blocks sending a new message while a turn awaits approval', function () {
    [$user, $conversation] = approvalFixture();

    pauseTurn($conversation, $user, [[
        'id' => 'call_1', 'name' => 'ToolA', 'arguments' => [], 'reason' => 'a',
    ]]);

    Bus::fake();

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->set('message', 'meanwhile...')
        ->call('sendMessage');

    Bus::assertNotDispatched(ProcessChatMessage::class);
});

it('denies resolving an approval on a conversation the user cannot send to', function () {
    [$user, $conversation] = approvalFixture();

    pauseTurn($conversation, $user, [[
        'id' => 'call_1', 'name' => 'ToolA', 'arguments' => [], 'reason' => 'a',
    ]]);

    Gate::policy(Conversation::class, ToolApprovalViewOnlyPolicy::class);

    Bus::fake();

    Livewire::test('ai-kit::components.chat.conversation', ['conversation' => $conversation, 'agent' => EchoAgent::class])
        ->call('approvePendingCall', 'call_1')
        ->assertForbidden();

    Bus::assertNotDispatched(ProcessChatMessage::class);
});
