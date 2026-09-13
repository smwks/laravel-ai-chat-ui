<?php

use Smwks\LaravelAiKit\LaravelAiKitServiceProvider;

it('boots the service provider', function () {
    expect(
        app()->getProviders(LaravelAiKitServiceProvider::class)
    )->not->toBeEmpty();
});
