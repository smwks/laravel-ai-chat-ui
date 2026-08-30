# Laravel AI Chat UI

*`smwks/laravel-ai-chat-ui`*

![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)
![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg)
[![Tests](https://github.com/smwks/laravel-ai-chat-ui/actions/workflows/tests.yml/badge.svg)](https://github.com/smwks/laravel-ai-chat-ui/actions/workflows/tests.yml)

A drop-in Livewire chat UI and trace inspector for [`laravel/ai`](https://github.com/laravel/ai)
agents. Give your users a real conversation UI, and give yourself a "show thoughts" panel that
replays every LLM request/response, tool invocation, and raw HTTP exchange behind each reply —
without building any of it yourself.

It ships as three plain, presentation-only Livewire components with no opinion on your routes or
page chrome. Drop them into pages your own app already owns — or straight into a
[Filament](https://filamentphp.com) panel page. Same components, either way.

## Features

- **A real conversation UI** — new-conversation form, searchable/paginated history, and a
  threaded view, all wired to `laravel/ai`'s own conversation persistence.
- **A full trace inspector** — every LLM request/response, tool call, and raw HTTP exchange
  behind a reply, correctly nested (a tool's own HTTP calls render under that tool, not
  interleaved chronologically above it) and collapsible per-message.
- **A built-in JSON tree viewer** — no external JS dependency; click-to-collapse, and a search
  box that highlights matches and auto-expands their ancestors.
- **Multi-agent / multi-bot support** — run one agent app-wide via config, or pass a different
  agent per conversation for a site running several distinct bots.
- **Policy-gated by default** — the trace inspector is guarded by a `viewThoughts` ability you
  can override, in addition to a component-level `showThoughts` prop.
- **Extensible** — give any tool its own detail view or "thinking…" status message without
  forking or publishing anything.
- **Works in plain Livewire pages and in Filament panels** — see "Quick usage" below.

## Requirements

- PHP ^8.3
- Laravel ^12.0 or ^13.0
- Livewire ^4.1
- [`laravel/ai`](https://github.com/laravel/ai) ^0.11.0 — still pre-1.0, so its own API
  may change between releases; pin it deliberately in your own `composer.json`.

## Prerequisite

This package does not store conversations or messages itself — it builds entirely on
`laravel/ai`'s own `agent_conversations` / `agent_conversation_messages` tables. Its own
two tables, `agent_conversation_turns` and `agent_conversation_events`, follow that same
naming so they read as companions rather than a separate schema. Publish and run
`laravel/ai`'s migrations first:

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
as `laravel/ai`'s own provider/API key is configured. See "Using your own agent" to swap
in your own.

## Quick usage

The package registers no routes and owns no page chrome — you decide where these components
live. Two common hosts:

**A plain Livewire page you already own:**

```blade
{{-- resources/views/pages/chat/⚡conversation.blade.php --}}
<livewire:ai-chat-ui::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
/>
```

**A Filament panel page:**

```php
// app/Filament/Pages/Chat.php
use Filament\Pages\Page;
use Laravel\Ai\Models\Conversation;

class Chat extends Page
{
    protected string $view = 'filament.pages.chat';

    public ?Conversation $conversation = null;
}
```

```blade
{{-- resources/views/filament/pages/chat.blade.php --}}
<x-filament-panels::page>
    @if ($conversation)
        <livewire:ai-chat-ui::components.chat.conversation :conversation="$conversation" />
    @else
        <livewire:ai-chat-ui::components.chat.new />
    @endif
</x-filament-panels::page>
```

Both examples above are deliberately trimmed for a first look — see "Embedding these
components" and "Navigation events" below for wiring `chat.new`/`chat.history`'s browser
events into full page navigation, and the full prop reference for `chat.conversation`.

## Embedding these components

This package registers no routes and owns no URL structure or page-level navigation —
that's entirely the consuming app's job. It ships three embeddable, presentation-only
Livewire components under the `ai-chat-ui::components.chat` namespace:

- `ai-chat-ui::components.chat.new` — a form to start a new conversation.
- `ai-chat-ui::components.chat.history` — a searchable, paginated list of the
  authenticated user's conversations.
- `ai-chat-ui::components.chat.conversation` — the thread + "show thoughts" trace
  inspector for one conversation. Requires a `conversation` prop (a
  `Laravel\Ai\Models\Conversation` instance) and accepts these optional props:
  - `initialMessage` (string) — auto-send a first message on mount.
  - `agent` (class name string) — use an agent other than `config('ai-chat-ui.agent')`
    for this conversation; see "Using your own agent" for running more than one bot.
  - `showThoughts` (bool, default `true`) — whether the "show thoughts" trace
    inspector is available at all. This is ANDed with the `viewThoughts` policy
    ability below — both must allow it for a user to see it.
  - `showIds` (bool, default `true`) — whether the conversation id and each trace
    event's id are shown (click-to-copy) in the UI.

All three components require an authenticated user — they call `Auth::user()`
internally and will throw rather than gracefully 403 for a guest. Your own routes/pages
must enforce authentication (e.g. `Route::middleware(['web', 'auth'])`, or a Filament
panel's own auth guard) before embedding any of them.

### Navigation events

Two of the components dispatch a Livewire browser event instead of redirecting
themselves, since only your app knows what its own routes (or Filament page state) look
like:

| Event | Payload | Dispatched by |
|---|---|---|
| `ai-chat-ui-conversation-started` | `conversationId: string`, `message: string` | `components.chat.new`, after creating a new conversation |
| `ai-chat-ui-conversation-selected` | `conversationId: string` | `components.chat.history`, when a row is picked |

Your own page-level Livewire component (or Filament page) listens for these (via
Livewire's `#[On(...)]` attribute) and decides where to go. On a plain route, that
usually means redirecting:

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

On a Filament page, there's no route to redirect to — the same event just swaps which
component the page renders (see the Filament example under "Quick usage"):

```php
#[On('ai-chat-ui-conversation-started')]
public function onConversationStarted(string $conversationId, string $message): void
{
    $this->conversation = Conversation::findOrFail($conversationId);
    $this->initialMessage = $message;
}
```

## The JSON tree viewer

Bodies and raw payloads in the "show thoughts" detail panels (HTTP Exchange, Tool,
LLM Request, LLM Response) render through a small built-in JSON tree viewer — no
external JS dependency, just a recursive Blade partial with Alpine handling
interaction. It's intentionally plain: monochrome, 2-space indentation, no visible
buttons or chrome except the search box.

- **Collapse/expand** — click an opening `{` or `[` to collapse that object/array to
  `{ ... }` / `[ ... ]` inline; click it again to re-expand. Every object/array has its
  own independent collapsed state.
- **Search** — the box at the top does a plain-text search across the whole tree (not
  JSON-aware), highlights every match, and automatically expands any collapsed
  ancestor so a match is never hidden inside a collapsed node.

The `generic` fallback partial (used for event types with no dedicated view) still
renders its Raw tab through an older `<json-viewer>` custom element rather than this
one — a known inconsistency, not yet swapped over.

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

### Running more than one bot

`config('ai-chat-ui.agent')` is a single, app-wide default. For a site with several
distinct bots, pass the `agent` prop to `components.chat.conversation` instead — it
overrides the config default for that conversation:

```blade
{{-- resources/views/pages/support/chat/⚡conversation.blade.php --}}
<livewire:ai-chat-ui::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
    agent="App\Ai\Agents\SupportAgent"
/>
```

```blade
{{-- resources/views/pages/sales/chat/⚡conversation.blade.php --}}
<livewire:ai-chat-ui::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
    agent="App\Ai\Agents\SalesAgent"
/>
```

This package doesn't persist which agent a conversation belongs to — it has no
tables of its own (see "Prerequisite"). So resuming a conversation correctly depends
on your own routing consistently pairing a conversation with the right agent, e.g. by
giving each bot its own URL prefix (`/support/chat/{conversation}` vs
`/sales/chat/{conversation}`) the way the two pages above illustrate, rather than one
shared `chat.conversation` route used for every bot.

## Trace correlation — important if you extend this package

Which conversation/turn is "in flight" is tracked via Laravel's `Context` facade, not
stored on the agent object — the agent binding is **not** a singleton. If you add code
that captures more trace data, read these `Context` keys rather than reaching for agent
identity:

| Key | Set by | Meaning |
|---|---|---|
| `ai-chat-ui.conversation_id` / `ai-chat-ui.turn_id` | `ProcessChatMessage::handle()`, once at the top | Which conversation/turn is in flight. Every capture listener below is a no-op unless `conversation_id` is present. |
| `ai-chat-ui.tool_source` | `CaptureToolInvoking`, for the duration of that tool's `handle()` call | Attributes any HTTP call captured during that window to the tool rather than to the LLM provider — see `payload['source']` on an `http.exchange` event. |
| `ai-chat-ui.tool_invocation_id` | `CaptureToolInvoking`, for the duration of that tool's `handle()` call | Correlates an `http.exchange` event back to the specific `tool.invoked` event it belongs to, so the UI can nest it under the right tool call even when the same tool runs more than once in a turn. |
| `ai-chat-ui.pending_http_request` | The request-side HTTP middleware, cleared by the response-side middleware | Correlates a request with its response into one `http.exchange` event. Assumes calls through `Http::` happen one at a time; a concurrent caller (e.g. `Http::pool()`) would need its own correlation id instead. |

## Extension points

- Override `Smwks\LaravelAiChatUi\Policies\ConversationPolicy::viewThoughts()` (or swap
  in your own policy via `Gate::policy(Conversation::class, ...)`) to restrict who can
  see the "show thoughts" trace inspector — it defaults to the same rule as `view()`.
  This is checked in addition to, not instead of, the `showThoughts` component prop.
- Publish views (`--tag=ai-chat-ui-views`) and override
  `components/chat/partials/thought-details/generic.blade.php` to render domain-specific
  structured-output payloads.
- Give a specific tool its own "show thoughts" detail view via `config('ai-chat-ui.tool_views')`,
  keyed by the tool name found in a `tool.invoked` event's `payload['tool']`:

  ```php
  // config/ai-chat-ui.php
  'tool_views' => [
      'weather' => 'chat.tools.weather-details',
  ],
  ```

  The view receives an `event` prop (a `ConversationEvent`). Tools not listed here keep
  rendering through the package's own generic tool partial — no need to publish or fork
  anything just to add one tool's view.
- Give a tool its own "thinking" status message by implementing
  `Smwks\LaravelAiChatUi\Contracts\HasStatusMessage`:

  ```php
  use Smwks\LaravelAiChatUi\Contracts\HasStatusMessage;

  class WeatherTool implements Tool, HasStatusMessage
  {
      public function statusMessage(array $arguments): string
      {
          return "Checking the weather in {$arguments['city']}…";
      }

      // ...description(), handle(), schema()
  }
  ```

  While that tool is running, the chat UI's "thinking" indicator shows this string
  instead of a generic "Thinking…" — the collapsed toggle it lives in also expands, on
  click, into the same live trace boxes the completed view shows. A tool that doesn't
  implement this interface falls back to its own `description()`.
- Out of scope in this release: entity typeahead, Markdown export, an SSE/JSON API,
  human tool-approval UI, broadcasting, and per-conversation rating/notes.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT — see [LICENSE.md](LICENSE.md).
