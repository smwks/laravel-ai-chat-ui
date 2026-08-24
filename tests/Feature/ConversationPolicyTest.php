<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

class ConversationPolicyTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name'];
}

it('allows only the owning participant to send messages', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $owner = ConversationPolicyTestUser::create(['name' => 'Owner']);
    $stranger = ConversationPolicyTestUser::create(['name' => 'Stranger']);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => ConversationPolicyTestUser::class,
        'participant_id' => $owner->id,
        'title' => 'Test conversation',
    ]);

    expect(Gate::forUser($owner)->allows('sendMessage', $conversation))->toBeTrue();
    expect(Gate::forUser($stranger)->allows('sendMessage', $conversation))->toBeFalse();
});

it('authorizes the owning participant when the host app registers a morph map', function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    Relation::morphMap(['user' => ConversationPolicyTestUser::class]);

    try {
        $owner = ConversationPolicyTestUser::create(['name' => 'Morph-Mapped Owner']);
        $stranger = ConversationPolicyTestUser::create(['name' => 'Morph-Mapped Stranger']);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid7(),
            'participant_type' => Conversation::participantType($owner),
            'participant_id' => Conversation::participantKey($owner),
            'title' => 'Morph-mapped conversation',
        ]);

        expect($conversation->participant_type)->toBe('user');
        expect(Gate::forUser($owner)->allows('sendMessage', $conversation))->toBeTrue();
        expect(Gate::forUser($owner)->allows('view', $conversation))->toBeTrue();
        expect(Gate::forUser($stranger)->allows('sendMessage', $conversation))->toBeFalse();
    } finally {
        Relation::morphMap([], false);
    }
});
