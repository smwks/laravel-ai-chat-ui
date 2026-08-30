<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

it('resolves a {conversation} route segment by key instead of every column', function () {
    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 1,
        'title' => 'Route binding test',
    ]);

    Route::get('/ai-chat-ui-test/{conversation}', fn (Conversation $conversation) => $conversation->id)
        ->middleware(SubstituteBindings::class);

    $this->get('/ai-chat-ui-test/'.$conversation->id)
        ->assertOk()
        ->assertSeeText($conversation->id);
});

it('404s for a {conversation} segment that does not match any conversation', function () {
    Route::get('/ai-chat-ui-test/{conversation}', fn (Conversation $conversation) => $conversation->id)
        ->middleware(SubstituteBindings::class);

    $this->get('/ai-chat-ui-test/does-not-exist')->assertNotFound();
});
