<?php

namespace Smwks\LaravelAiKit\Turns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ConversationEvent extends Model
{
    const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->id ??= (string) Str::uuid7();
        });
    }

    public function getTable(): string
    {
        return config('ai-kit.turns.tables.events', 'agent_conversation_events');
    }
}
