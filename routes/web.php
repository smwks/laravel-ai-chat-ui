<?php

use Illuminate\Support\Facades\Route;

Route::prefix(config('ai-chat-ui.routes.prefix', 'chat'))
    ->middleware(config('ai-chat-ui.routes.middleware', ['web', 'auth']))
    ->group(function () {
        Route::livewire('/', 'ai-chat-ui::pages.chat.new')
            ->name(config('ai-chat-ui.routes.names.new', 'chat.new'));

        Route::livewire('/history', 'ai-chat-ui::pages.chat.history')
            ->name(config('ai-chat-ui.routes.names.history', 'chat.history'));

        Route::livewire('/{conversation}', 'ai-chat-ui::pages.chat.conversation')
            ->name(config('ai-chat-ui.routes.names.conversation', 'chat.conversation'));
    });
