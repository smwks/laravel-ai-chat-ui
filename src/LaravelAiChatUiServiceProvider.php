<?php

namespace Smwks\LaravelAiChatUi;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;
use Smwks\LaravelAiChatUi\Console\Commands\InstallCommand;
use Smwks\LaravelAiChatUi\Listeners\CaptureAgentRequest;
use Smwks\LaravelAiChatUi\Listeners\CaptureAgentResponse;
use Smwks\LaravelAiChatUi\Listeners\CaptureToolInvoked;
use Smwks\LaravelAiChatUi\Listeners\CaptureToolInvoking;
use Smwks\LaravelAiChatUi\Models\ConversationEvent;
use Smwks\LaravelAiChatUi\Policies\ConversationPolicy;
use Smwks\LaravelAiChatUi\Services\QueryTracker;

class LaravelAiChatUiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-chat-ui.php', 'ai-chat-ui');

        $this->app->singleton(QueryTracker::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-chat-ui');

        // Register the "ai-chat-ui" namespace with Livewire's own component
        // finder (separate from the Blade view namespace above) so that
        // Route::livewire('...', 'ai-chat-ui::pages.chat.*') can resolve the
        // single-file components registered in routes/web.php.
        Livewire::addNamespace('ai-chat-ui', __DIR__.'/../resources/views');

        $this->publishes([
            __DIR__.'/../config/ai-chat-ui.php' => config_path('ai-chat-ui.php'),
        ], 'ai-chat-ui-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_08_23_000001_create_ai_chat_ui_turns_table.php' => database_path('migrations/2026_08_23_000001_create_ai_chat_ui_turns_table.php'),
            __DIR__.'/../database/migrations/2026_08_23_000002_create_ai_chat_ui_events_table.php' => database_path('migrations/2026_08_23_000002_create_ai_chat_ui_events_table.php'),
        ], 'ai-chat-ui-migrations');

        $this->publishes([
            __DIR__.'/../resources/js/json-viewer.min.js' => public_path('vendor/ai-chat-ui/json-viewer.min.js'),
        ], 'ai-chat-ui-assets');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ai-chat-ui'),
        ], 'ai-chat-ui-views');

        $this->registerHttpCaptureMiddleware();

        Event::listen(PromptingAgent::class, CaptureAgentRequest::class);
        Event::listen(AgentPrompted::class, CaptureAgentResponse::class);
        Event::listen(InvokingTool::class, CaptureToolInvoking::class);
        Event::listen(ToolInvoked::class, CaptureToolInvoked::class);

        Gate::policy(
            Conversation::class,
            ConversationPolicy::class
        );

        if (config('ai-chat-ui.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    protected function registerHttpCaptureMiddleware(): void
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'cookie', 'set-cookie'];

        Http::globalRequestMiddleware(function ($request) use ($sensitiveHeaders) {
            if (! Context::has('ai-chat-ui.conversation_id')) {
                return $request;
            }

            $headers = array_diff_key(
                array_change_key_case($request->getHeaders(), CASE_LOWER),
                array_flip($sensitiveHeaders)
            );

            ConversationEvent::create([
                'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
                'turn_id' => Context::get('ai-chat-ui.turn_id'),
                'event_type' => 'http.request',
                'payload' => [
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                    'headers' => $headers,
                    'body' => json_decode((string) $request->getBody(), true),
                ],
            ]);

            return $request;
        });

        Http::globalResponseMiddleware(function ($response) use ($sensitiveHeaders) {
            if (! Context::has('ai-chat-ui.conversation_id')) {
                return $response;
            }

            $headers = array_diff_key(
                array_change_key_case($response->getHeaders(), CASE_LOWER),
                array_flip($sensitiveHeaders)
            );

            ConversationEvent::create([
                'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
                'turn_id' => Context::get('ai-chat-ui.turn_id'),
                'event_type' => 'http.response',
                'payload' => [
                    'status' => $response->getStatusCode(),
                    'headers' => $headers,
                    'body' => json_decode((string) $response->getBody(), true),
                ],
            ]);

            return $response;
        });
    }
}
