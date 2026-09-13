<?php

use Illuminate\Support\Facades\Schema;
use Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus;

it('creates the turns and events tables', function () {
    expect(Schema::hasTable('agent_conversation_turns'))->toBeTrue();
    expect(Schema::hasColumns('agent_conversation_turns', [
        'id', 'conversation_id', 'participant_type', 'participant_id', 'status', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('agent_conversation_events'))->toBeTrue();
    expect(Schema::hasColumns('agent_conversation_events', [
        'id', 'conversation_id', 'turn_id', 'event_type', 'payload', 'created_at',
    ]))->toBeTrue();
});

it('defines the turn status cases', function () {
    expect(ConversationTurnStatus::Pending->value)->toBe('PENDING');
    expect(ConversationTurnStatus::Processing->value)->toBe('PROCESSING');
    expect(ConversationTurnStatus::Complete->value)->toBe('COMPLETE');
    expect(ConversationTurnStatus::Failed->value)->toBe('FAILED');
    expect(ConversationTurnStatus::Pending->label())->toBe('Pending');
});
