<?php

use Illuminate\Support\Facades\Route;
use Laravel\Ai\Models\Conversation;

Route::middleware(['auth'])->prefix('chat')->name('chat.')->group(function () {
    Route::get('/', fn () => view('pages.chat.new'))->name('new');

    Route::get('/history', fn () => view('pages.chat.history'))->name('history');

    Route::get('/{conversation}', function (Conversation $conversation) {
        return view('pages.chat.conversation', [
            'conversation' => $conversation,
            'initialMessage' => session()->pull('chat.initial_message'),
        ]);
    })->name('conversation');
});
