<?php

namespace Smwks\LaravelAiChatUi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Smwks\LaravelAiChatUi\Enums\ConversationTurnStatus;

class ConversationTurn extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status' => ConversationTurnStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $turn) {
            $turn->id ??= (string) Str::uuid7();
        });
    }

    public function getTable(): string
    {
        return config('ai-chat-ui.tables.turns', 'ai_chat_ui_turns');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function participant(): MorphTo
    {
        return $this->morphTo();
    }
}
