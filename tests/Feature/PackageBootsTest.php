<?php

use Smwks\LaravelAiChatUi\LaravelAiChatUiServiceProvider;

it('boots the service provider', function () {
    expect(
        app()->getProviders(LaravelAiChatUiServiceProvider::class)
    )->not->toBeEmpty();
});
