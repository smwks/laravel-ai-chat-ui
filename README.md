# smwks/laravel-ai-chat-ui

A Livewire chat UI and trace inspector for `laravel/ai` agents: a landing page to start a
conversation, a searchable history list, and a thread view with a "show thoughts" panel
that replays every LLM request/response, tool invocation, and raw HTTP exchange behind
each assistant reply.

## Prerequisite

This package does not store conversations or messages itself — it builds entirely on
`laravel/ai`'s own `agent_conversations` / `agent_conversation_messages` tables. Publish
and run `laravel/ai`'s migrations first:

```bash
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

Run `php artisan ai-chat-ui:install` at any time to check this prerequisite is satisfied.

## Installation

```bash
composer require smwks/laravel-ai-chat-ui
php artisan vendor:publish --tag=ai-chat-ui-config
php artisan vendor:publish --tag=ai-chat-ui-migrations
php artisan vendor:publish --tag=ai-chat-ui-assets
php artisan migrate
```

Then register your own routes — see "Routing" below. Out of the box, the package uses
`Smwks\LaravelAiChatUi\Testbench\EchoAgent` — a trivial agent with no tools — so the install
is runnable without any host-app agent code, as long as `laravel/ai`'s own provider/API key
is configured.

## Routing

This package registers no routes and owns no URL structure — that's entirely up to the
consuming app. It ships three Livewire single-file components under the `ai-chat-ui::`
namespace; register them as full-page routes wherever and however you like:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('chat')->group(function () {
    Route::livewire('/', 'ai-chat-ui::pages.chat.new')->name('chat.new');
    Route::livewire('/history', 'ai-chat-ui::pages.chat.history')->name('chat.history');
    Route::livewire('/{conversation}', 'ai-chat-ui::pages.chat.conversation')->name('chat.conversation');
});
```

The `{conversation}` route parameter name must match `chat.conversation`'s
`mount(Conversation $conversation)` signature for Laravel's implicit model binding to
resolve it — standard Laravel routing, nothing package-specific.

The package's own Blade views generate their internal cross-links (the "History" link on
`chat.new`, the redirect target after starting a conversation, etc.) via
`route(config('ai-chat-ui.routes.names.*'))`, not hardcoded route names. If you name your
routes anything other than `chat.new` / `chat.history` / `chat.conversation`, update
`config('ai-chat-ui.routes.names')` (published via `--tag=ai-chat-ui-config`) to match.

You're also free to embed just one of these components inside a page you already own,
rather than giving it a dedicated route — the `<livewire:ai-chat-ui::pages.chat.new />` tag
syntax works anywhere once the package's service provider has booted.

## Using your own agent

Set `config('ai-chat-ui.agent')` to your own agent's class name. It only needs to satisfy
`laravel/ai`'s own `Agent` + `Conversational` interfaces, using the `Promptable` and
`RemembersConversations` traits — nothing from this package is required on the agent
itself:

```php
class SupportAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string { /* ... */ }
}
```
```php
// config/ai-chat-ui.php
'agent' => App\Ai\Agents\SupportAgent::class,
```

## Trace correlation — important if you extend this package

Which conversation/turn is "in flight" is tracked via Laravel's `Context` facade
(`ai-chat-ui.conversation_id` / `ai-chat-ui.turn_id`), set once at the top of
`ProcessChatMessage::handle()`. It is **not** stored on the agent object, and the agent
binding is **not** a singleton. If you add code that captures more trace data, read these
`Context` keys rather than reaching for agent identity — see the design spec for the full
reasoning.

## Extension points

- Publish views (`--tag=ai-chat-ui-views`) and override
  `pages/chat/partials/thought-details/generic.blade.php` to render domain-specific
  structured-output payloads.
- Out of scope in this release: entity typeahead, Markdown export, an SSE/JSON API,
  human tool-approval UI, broadcasting, and per-conversation rating/notes.
