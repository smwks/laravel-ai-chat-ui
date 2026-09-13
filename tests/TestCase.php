<?php

namespace Smwks\LaravelAiKit\Tests;

use Laravel\Ai\AiServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Smwks\LaravelAiKit\LaravelAiKitServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LivewireServiceProvider::class,
            LaravelAiKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('queue.default', 'sync');

        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai', [
            'driver' => 'openai',
            'key' => 'test-key',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/ai/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
