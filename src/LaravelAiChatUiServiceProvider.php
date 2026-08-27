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
        // finder (separate from the Blade view namespace above) so that a
        // consuming app's own <livewire:ai-chat-ui::components.chat.*> tags
        // (or a direct Route::livewire() call, if preferred) can resolve
        // these components. The package registers no routes of its own —
        // see the README's "Embedding these components" section.
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
            __DIR__.'/../resources/js/json-tree-search.js' => public_path('vendor/ai-chat-ui/json-tree-search.js'),
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    protected function registerHttpCaptureMiddleware(): void
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'cookie', 'set-cookie'];

        // Request and response are captured as one "http.exchange" event, written
        // only once the response arrives. The request side is stashed in Context
        // between the two middleware calls rather than persisted on its own — this
        // assumes calls made through Http:: happen one at a time (true for every
        // caller in this package today: the provider's own client and a tool's
        // handle()); a concurrent caller (e.g. Http::pool()) would need its own
        // correlation id instead of this shared Context key.
        Http::globalRequestMiddleware(function ($request) use ($sensitiveHeaders) {
            if (! Context::has('ai-chat-ui.conversation_id')) {
                return $request;
            }

            $headers = array_diff_key(
                array_change_key_case($request->getHeaders(), CASE_LOWER),
                array_flip($sensitiveHeaders)
            );

            Context::add('ai-chat-ui.pending_http_request', [
                'started_at' => hrtime(true),
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $headers,
                'body' => json_decode((string) $request->getBody(), true),
            ]);

            return $request;
        });

        Http::globalResponseMiddleware(function ($response) use ($sensitiveHeaders) {
            $pending = Context::get('ai-chat-ui.pending_http_request');

            Context::forget('ai-chat-ui.pending_http_request');

            if (! Context::has('ai-chat-ui.conversation_id') || ! $pending) {
                return $response;
            }

            $headers = array_diff_key(
                array_change_key_case($response->getHeaders(), CASE_LOWER),
                array_flip($sensitiveHeaders)
            );

            ConversationEvent::create([
                'conversation_id' => Context::get('ai-chat-ui.conversation_id'),
                'turn_id' => Context::get('ai-chat-ui.turn_id'),
                'event_type' => 'http.exchange',
                'payload' => [
                    'source' => Context::get('ai-chat-ui.tool_source', 'provider'),
                    'tool_invocation_id' => Context::get('ai-chat-ui.tool_invocation_id'),
                    'method' => $pending['method'],
                    'url' => $pending['url'],
                    'status' => $response->getStatusCode(),
                    'duration_ms' => (hrtime(true) - $pending['started_at']) / 1_000_000,
                    'request' => [
                        'headers' => $pending['headers'],
                        'body' => $pending['body'],
                    ],
                    'response' => [
                        'headers' => $headers,
                        'body' => json_decode((string) $response->getBody(), true),
                    ],
                ],
            ]);

            return $response;
        });
    }
}
