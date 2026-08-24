<?php

namespace Smwks\LaravelAiChatUi\Tests;

use Laravel\Ai\AiServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Smwks\LaravelAiChatUi\LaravelAiChatUiServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LivewireServiceProvider::class,
            LaravelAiChatUiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Livewire's page components (Route::livewire(...)) wrap their output in
        // "layouts::app" by default. Real host apps ship that layout themselves;
        // stand one in for the test suite so page-component routes render.
        $app['view']->addNamespace('layouts', __DIR__.'/resources/views/layouts');

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

    // The package registers no routes of its own (see the README's "Routing"
    // section) — this simulates a consuming app's own routes/web.php so tests
    // that exercise real HTTP routing (rather than Livewire::test(), which
    // mounts components directly without routing) have something to hit.
    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'auth'])->prefix('chat')->group(function () use ($router) {
            $router->livewire('/', 'ai-chat-ui::pages.chat.new')->name('chat.new');
            $router->livewire('/history', 'ai-chat-ui::pages.chat.history')->name('chat.history');
            $router->livewire('/{conversation}', 'ai-chat-ui::pages.chat.conversation')->name('chat.conversation');
        });
    }
}
