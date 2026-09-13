<?php

namespace Smwks\LaravelAiKit;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;
use Smwks\LaravelAiKit\Chat\Policies\ConversationPolicy;
use Smwks\LaravelAiKit\Console\Commands\InstallCommand;
use Smwks\LaravelAiKit\Turns\Listeners\CaptureAgentRequest;
use Smwks\LaravelAiKit\Turns\Listeners\CaptureAgentResponse;
use Smwks\LaravelAiKit\Turns\Listeners\CaptureToolApprovalRequested;
use Smwks\LaravelAiKit\Turns\Listeners\CaptureToolApprovalResolved;
use Smwks\LaravelAiKit\Turns\Listeners\CaptureToolInvoked;
use Smwks\LaravelAiKit\Turns\Listeners\CaptureToolInvoking;
use Smwks\LaravelAiKit\Turns\Models\ConversationEvent;
use Smwks\LaravelAiKit\Turns\Services\QueryTracker;

class LaravelAiKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-kit.php', 'ai-kit');

        $this->app->singleton(QueryTracker::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-kit');

        // Register the "ai-kit" namespace with Livewire's own component
        // finder (separate from the Blade view namespace above) so that a
        // consuming app's own <livewire:ai-kit::components.chat.*> tags
        // (or a direct Route::livewire() call, if preferred) can resolve
        // these components. The package registers no routes of its own —
        // see the README's "Embedding these components" section.
        Livewire::addNamespace('ai-kit', __DIR__.'/../resources/views');

        $this->publishes([
            __DIR__.'/../config/ai-kit.php' => config_path('ai-kit.php'),
        ], 'ai-kit-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_08_23_000001_create_agent_conversation_turns_table.php' => database_path('migrations/2026_08_23_000001_create_agent_conversation_turns_table.php'),
            __DIR__.'/../database/migrations/2026_08_23_000002_create_agent_conversation_events_table.php' => database_path('migrations/2026_08_23_000002_create_agent_conversation_events_table.php'),
        ], 'ai-kit-migrations');

        $this->publishes([
            __DIR__.'/../resources/js/json-viewer.min.js' => public_path('vendor/ai-kit/json-viewer.min.js'),
            __DIR__.'/../resources/js/json-tree-search.js' => public_path('vendor/ai-kit/json-tree-search.js'),
            __DIR__.'/../resources/css/reply-body.css' => public_path('vendor/ai-kit/reply-body.css'),
        ], 'ai-kit-chat-assets');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ai-kit'),
        ], 'ai-kit-chat-views');

        $this->registerHttpCaptureMiddleware();

        Event::listen(PromptingAgent::class, CaptureAgentRequest::class);
        Event::listen(AgentPrompted::class, CaptureAgentResponse::class);
        Event::listen(InvokingTool::class, CaptureToolInvoking::class);
        Event::listen(ToolInvoked::class, CaptureToolInvoked::class);
        Event::listen(ToolApprovalRequested::class, CaptureToolApprovalRequested::class);
        Event::listen(ToolApprovalResolved::class, CaptureToolApprovalResolved::class);

        Gate::policy(
            Conversation::class,
            ConversationPolicy::class
        );

        // Laravel's implicit route-model binding matches a {conversation} segment name
        // to the Conversation class, then hands Livewire's serialized property value
        // back as the binding value — resolveRouteBinding() ends up querying by every
        // column instead of just the key, and 404s. Binding the parameter name
        // explicitly (by key only) sidesteps that regardless of which property
        // triggered the (re)bind. A host app that registers its own explicit binding
        // for "conversation" later (e.g. in routes/web.php) overrides this one, since
        // Route::bind() for the same name simply replaces the prior resolver.
        Route::bind('conversation', fn (string $value) => Conversation::findOrFail($value));

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
            if (! Context::has('ai-kit.conversation_id')) {
                return $request;
            }

            $headers = array_diff_key(
                array_change_key_case($request->getHeaders(), CASE_LOWER),
                array_flip($sensitiveHeaders)
            );

            Context::add('ai-kit.pending_http_request', [
                'started_at' => hrtime(true),
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $headers,
                'body' => json_decode((string) $request->getBody(), true),
            ]);

            return $request;
        });

        Http::globalResponseMiddleware(function ($response) use ($sensitiveHeaders) {
            $pending = Context::get('ai-kit.pending_http_request');

            Context::forget('ai-kit.pending_http_request');

            if (! Context::has('ai-kit.conversation_id') || ! $pending) {
                return $response;
            }

            $headers = array_diff_key(
                array_change_key_case($response->getHeaders(), CASE_LOWER),
                array_flip($sensitiveHeaders)
            );

            ConversationEvent::create([
                'conversation_id' => Context::get('ai-kit.conversation_id'),
                'turn_id' => Context::get('ai-kit.turn_id'),
                'event_type' => 'http.exchange',
                'payload' => [
                    'source' => Context::get('ai-kit.tool_source', 'provider'),
                    'tool_invocation_id' => Context::get('ai-kit.tool_invocation_id'),
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
