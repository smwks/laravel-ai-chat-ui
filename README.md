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

Out of the box, the package uses `Smwks\LaravelAiChatUi\Testbench\EchoAgent` — a trivial
agent with no tools — so the install is runnable without any host-app agent code, as long
as `laravel/ai`'s own provider/API key is configured. Then build your own pages that embed
the three components below — see "Embedding these components."

## Embedding these components

This package registers no routes and owns no URL structure or page-level navigation —
that's entirely the consuming app's job. It ships three embeddable, presentation-only
Livewire components under the `ai-chat-ui::components.chat` namespace:

- `ai-chat-ui::components.chat.new` — a form to start a new conversation.
- `ai-chat-ui::components.chat.history` — a searchable, paginated list of the
  authenticated user's conversations.
- `ai-chat-ui::components.chat.conversation` — the thread + "show thoughts" trace
  inspector for one conversation. Requires a `conversation` prop (a
  `Laravel\Ai\Models\Conversation` instance) and accepts an optional `initialMessage`
  prop (a string) to auto-send a first message on mount.

All three components require an authenticated user — they call `Auth::user()`
internally and will throw rather than gracefully 403 for a guest. Your own routes/pages
must enforce authentication (e.g. `Route::middleware(['web', 'auth'])`) before embedding
any of them.

Drop them into pages your app already owns and routes:

```blade
{{-- resources/views/pages/chat/⚡new.blade.php --}}
<livewire:ai-chat-ui::components.chat.new />
```

```blade
{{-- resources/views/pages/chat/⚡conversation.blade.php --}}
<livewire:ai-chat-ui::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
/>
```

### Navigation events

Two of the components dispatch a Livewire browser event instead of redirecting
themselves, since only your app knows what its own routes are named:

| Event | Payload | Dispatched by |
|---|---|---|
| `ai-chat-ui-conversation-started` | `conversationId: string`, `message: string` | `components.chat.new`, after creating a new conversation |
| `ai-chat-ui-conversation-selected` | `conversationId: string` | `components.chat.history`, when a row is picked |

Your own page-level Livewire component listens for these (via Livewire's `#[On(...)]`
attribute) and decides where to go:

```php
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('ai-chat-ui-conversation-started')]
    public function onConversationStarted(string $conversationId, string $message): void
    {
        session()->flash('chat.initial_message', $message);

        $this->redirect(route('chat.conversation', $conversationId), navigate: true);
    }
}; ?>
```

Your `chat.conversation` page then reads that stashed message back out of the session and
passes it through as the `initialMessage` prop:

```php
public function mount(\Laravel\Ai\Models\Conversation $conversation): void
{
    $this->conversation = $conversation;
    $this->initialMessage = session()->pull('chat.initial_message');
}
```

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
  `components/chat/partials/thought-details/generic.blade.php` to render domain-specific
  structured-output payloads.
- Out of scope in this release: entity typeahead, Markdown export, an SSE/JSON API,
  human tool-approval UI, broadcasting, and per-conversation rating/notes.
