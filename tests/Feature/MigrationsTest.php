<?php

use Illuminate\Support\Facades\Schema;
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;

it('creates the turns and events tables', function () {
    expect(Schema::hasTable('ai_chat_ui_turns'))->toBeTrue();
    expect(Schema::hasColumns('ai_chat_ui_turns', [
        'id', 'conversation_id', 'participant_type', 'participant_id', 'status', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('ai_chat_ui_events'))->toBeTrue();
    expect(Schema::hasColumns('ai_chat_ui_events', [
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
