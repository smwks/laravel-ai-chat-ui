# Laravel AI Kit

*`smwks/laravel-ai-kit`*

![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)
![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg)
[![Tests](https://github.com/smwks/laravel-ai-kit/actions/workflows/tests.yml/badge.svg)](https://github.com/smwks/laravel-ai-kit/actions/workflows/tests.yml)

A component kit for building on [`laravel/ai`](https://github.com/laravel/ai): a turn/event
data model that gives an agent run durable status and a full execution trace, and a Livewire
chat UI built on top of it. Give your users a real conversation UI — messages run in a queued
job and their status survives a page reload, not just a live stream — and give yourself a
"show thoughts" panel that replays every LLM request/response, tool invocation, and raw HTTP
exchange behind each reply — without building any of it yourself.

The chat UI ships as three plain, presentation-only Livewire components with no opinion on
your routes or page chrome. Drop them into pages your own app already owns — or straight into
a [Filament](https://filamentphp.com) panel page. Same components, either way. Requiring the
package is all it takes for the turn/event data model to be active; the chat UI additionally
needs your own route pointing at a page that embeds its components — there are no feature
flags to flip either way.

## Features

- **A turn/event data model** — durable per-message status (`Pending → Processing → Complete
  | Failed | AwaitingApproval`) and a full trace log (LLM requests/responses, tool calls, raw
  HTTP exchanges), always active once the package is installed and migrated. See "Why this
  package needs its own tables".
- **A real conversation UI** — new-conversation form, searchable/paginated history, and a
  threaded view, all wired to `laravel/ai`'s own conversation persistence.
- **A full trace inspector** — every LLM request/response, tool call, and raw HTTP exchange
  behind a reply, correctly nested (a tool's own HTTP calls render under that tool, not
  interleaved chronologically above it) and collapsible per-message.
- **A built-in JSON tree viewer** — no external JS dependency; click-to-collapse, and a search
  box that highlights matches and auto-expands their ancestors.
- **Multi-agent / multi-bot support** — every embed of `chat.conversation` names its own agent
  via a required `agent` prop, so a site running several distinct bots just points each page at
  a different one.
- **Policy-gated by default** — the trace inspector is guarded by a `viewThoughts` ability you
  can override, in addition to a component-level `showThoughts` prop.
- **Human tool approval** — a tool that requires approval pauses the run and renders an
  inline Approve / Reject prompt (with its arguments and reason) in the thread; resolving
  it resumes the same turn. See "Human tool approval" below.
- **Extensible** — give any tool its own detail view or "thinking…" status message without
  forking or publishing anything.
- **Themeable without forking views** — every structural element carries a stable
  `data-ai-kit="<role>"` hook, and a component's own heading/width/padding can be
  turned off entirely for a host page that provides its own.
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

## Installation

```bash
composer require smwks/laravel-ai-kit
php artisan vendor:publish --tag=ai-kit-config
php artisan vendor:publish --tag=ai-kit-migrations
php artisan vendor:publish --tag=ai-kit-chat-assets
php artisan migrate
```

Run `php artisan ai-kit:install` at any time to verify both `laravel/ai`'s own tables and
this package's turns/events tables exist.

There is no app-wide default agent — every embed of `chat.conversation` passes its own
`agent` prop. `Smwks\LaravelAiKit\Testbench\EchoAgent` — a trivial agent with no tools — is
bundled for exactly this: point `agent` at it to get the install running end-to-end before
writing your own agent, as long as `laravel/ai`'s own provider/API key is configured. See
"Using your own agent" to swap in your own.

## Quick usage

The package registers no routes and owns no page chrome — you decide where these components
live. Two common hosts:

**A plain Livewire page you already own:**

```blade
{{-- resources/views/pages/chat/⚡conversation.blade.php --}}
<livewire:ai-kit::components.chat.conversation
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
        <livewire:ai-kit::components.chat.conversation :conversation="$conversation" />
    @else
        <livewire:ai-kit::components.chat.new />
    @endif
</x-filament-panels::page>
```

Both examples above are deliberately trimmed for a first look — see "Embedding these
components" and "Navigation events" below for wiring `chat.new`/`chat.history`'s browser
events into full page navigation, and the full prop reference for `chat.conversation`.

## Embedding these components

This package registers no routes and owns no URL structure or page-level navigation —
that's entirely the consuming app's job. It ships three embeddable, presentation-only
Livewire components under the `ai-kit::components.chat` namespace:

- `ai-kit::components.chat.new` — a form to start a new conversation.
- `ai-kit::components.chat.history` — a searchable, paginated list of the
  authenticated user's conversations.
- `ai-kit::components.chat.conversation` — the thread + "show thoughts" trace
  inspector for one conversation. Requires a `conversation` prop (a
  `Laravel\Ai\Models\Conversation` instance).

All three components require an authenticated user — they call `Auth::user()`
internally and will throw rather than gracefully 403 for a guest. Your own routes/pages
must enforce authentication (e.g. `Route::middleware(['web', 'auth'])`, or a Filament
panel's own auth guard) before embedding any of them.

### Presentation props

All three components accept:

- `showHeader` (bool, default `true`) — whether the component renders its own `<h1>`
  (and, for `chat.conversation`, the conversation id block below it). Turn off when the
  host page already renders its own page title, e.g. a Filament page's own heading.
- `containerClass` (string, default `null`) — classes for the component's root element.
  Left `null`, each component falls back to a sensible standalone layout (centered,
  max width, padding); pass an empty string, or your own classes, when the host page
  already constrains width/padding.

`chat.conversation` additionally accepts:

- `initialMessage` (string) — auto-send a first message on mount.
- `agent` (string, **required**) — the agent class name (or container binding key) this
  conversation runs; see "Using your own agent". There is no app-wide default, so every
  embed of `chat.conversation` must pass one.
- `showThoughts` (bool, default `true`) — whether the "show thoughts" trace
  inspector is available at all. This is ANDed with the `viewThoughts` policy
  ability below — both must allow it for a user to see it.
- `showIds` (bool, default `true`) — whether the conversation id and each trace
  event's id are shown (click-to-copy) in the UI.
- `fillHeight` (bool, default `false`) — makes the message thread its own scroll
  container, filling whatever height the host gives it, with the composer pinned
  below it. Only turn this on when the host actually hands the component a bounded
  height (e.g. a fixed-height panel region) — otherwise the thread has nothing to
  fill and won't scroll internally at all. Left off, the component renders with no
  fixed height and relies on an ancestor (the page itself, or a host-provided
  container) to scroll, so it never produces two nested scrollbars.
- `detailsZIndex` (int, default `50`) — z-index for the trace details slide-over
  panel, which is `x-teleport`'d to the end of `<body>`. Raise this when a host app's
  own modal/toast layer (e.g. Filament's) sits above the default.

### Styling hooks

Every structural element carries a `data-ai-kit="<role>"` attribute — `root`,
`header`, `thread`, `message` (plus `data-ai-kit-role="user"` / `"assistant"` on
message bubbles), `reply-body`, `composer`, `thoughts-toggle`, `thought-event` — so you
can theme any of it from your own stylesheet instead of forking views or writing
brittle descendant selectors:

```css
[data-ai-kit="message"][data-ai-kit-role="user"] {
    /* restyle the user's own message bubble */
}
```

`chat.conversation` also ships a small default stylesheet for `reply-body` (list
markers, heading/paragraph spacing, code/pre) — published via `--tag=ai-kit-chat-assets`
alongside the JSON tree viewer's JS, so an assistant's Markdown reply still reads as
structured text under a CSS reset like Filament's or Tailwind's Preflight. Override any
of its rules from your own stylesheet, or skip loading it and publish your own.

### Navigation events

Two of the components dispatch a Livewire browser event instead of redirecting
themselves, since only your app knows what its own routes (or Filament page state) look
like:

| Event | Payload | Dispatched by |
|---|---|---|
| `ai-kit-conversation-started` | `conversationId: string`, `message: string` | `components.chat.new`, after creating a new conversation |
| `ai-kit-conversation-selected` | `conversationId: string` | `components.chat.history`, when a row is picked |

Your own page-level Livewire component (or Filament page) listens for these (via
Livewire's `#[On(...)]` attribute) and decides where to go. On a plain route, that
usually means redirecting:

```php
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('ai-kit-conversation-started')]
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
#[On('ai-kit-conversation-started')]
public function onConversationStarted(string $conversationId, string $message): void
{
    $this->conversation = Conversation::findOrFail($conversationId);
    $this->initialMessage = $message;
}
```

### Filament: linkable, bookmarkable conversations

The swap-in-place approach above is the fastest way to get started, but it keeps every
conversation behind one URL — nothing to link to, bookmark, or open in a new tab. For
that, give each of the three components its own Filament page instead, the same way you
would with plain routes:

```php
// app/Filament/Pages/Chat.php
class Chat extends Page
{
    protected static ?string $slug = 'chat';

    public function sendMessage(): void {} // delegate to components.chat.new, or omit and let the component handle it directly
}
```

```php
// app/Filament/Pages/ChatHistory.php
class ChatHistory extends Page
{
    protected static ?string $slug = 'chat/history';
}
```

```php
// app/Filament/Pages/ChatConversation.php
class ChatConversation extends Page
{
    protected static ?string $slug = 'chat/{conversationId}';

    public ?Conversation $conversation = null;

    public ?string $initialMessage = null;

    public function mount(string $conversationId): void
    {
        $this->conversation = Conversation::findOrFail($conversationId);
    }
}
```

`Chat` and `ChatHistory`'s views listen for the two navigation events and redirect to
`ChatConversation`'s URL instead of swapping a property, mirroring the plain-route
example above:

```php
#[On('ai-kit-conversation-started')]
public function onConversationStarted(string $conversationId, string $message): void
{
    session()->flash('chat.initial_message', $message);

    $this->redirect(ChatConversation::getUrl(['conversationId' => $conversationId]));
}

#[On('ai-kit-conversation-selected')]
public function onConversationSelected(string $conversationId): void
{
    $this->redirect(ChatConversation::getUrl(['conversationId' => $conversationId]));
}
```

```php
// resources/views/filament/pages/chat-conversation.blade.php
<x-filament-panels::page>
    <livewire:ai-kit::components.chat.conversation
        :conversation="$conversation"
        :initial-message="$initialMessage ?? session()->pull('chat.initial_message')"
        :show-header="false"
        container-class=""
    />
</x-filament-panels::page>
```

`showHeader="false"` and an empty `containerClass` hand the page's own heading and width
constraints back to Filament's page chrome, instead of the component rendering its own
on top of them (see "Presentation props" above).

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

`chat.conversation`'s `agent` prop is required — there is no app-wide default. Point it at
your own agent's class name; it only needs to satisfy `laravel/ai`'s own `Agent` +
`Conversational` interfaces, using the `Promptable` and `RemembersConversations` traits —
nothing from this package is required on the agent itself:

```php
class SupportAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string { /* ... */ }
}
```

```blade
<livewire:ai-kit::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
    agent="App\Ai\Agents\SupportAgent"
/>
```

For a site running several distinct bots, just point each page's `chat.conversation` at a
different agent:

```blade
{{-- resources/views/pages/support/chat/⚡conversation.blade.php --}}
<livewire:ai-kit::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
    agent="App\Ai\Agents\SupportAgent"
/>
```

```blade
{{-- resources/views/pages/sales/chat/⚡conversation.blade.php --}}
<livewire:ai-kit::components.chat.conversation
    :conversation="$conversation"
    :initial-message="$initialMessage"
    agent="App\Ai\Agents\SalesAgent"
/>
```

This package doesn't persist which agent a conversation belongs to — neither of its own
tables (see "Why this package needs its own tables") has a column for it. So resuming a
conversation correctly depends on your own routing consistently pairing a conversation
with the right agent, e.g. by giving each bot its own URL prefix
(`/support/chat/{conversation}` vs `/sales/chat/{conversation}`) the way the two pages
above illustrate, rather than one shared `chat.conversation` route used for every bot.

### A `{conversation}` route segment and implicit binding

Naming a route parameter `conversation` (as in the examples above) triggers Laravel's
*implicit* route-model binding for `Laravel\Ai\Models\Conversation`. Livewire's own
`<livewire:...>` tag hands back a serialized representation of a typed property as the
binding value, and implicit binding resolves it by comparing every column rather than
just the key — which 404s. This package registers its own explicit binding for the
`conversation` parameter name (`Route::bind('conversation', ...)`, resolving by key
only) specifically to route around that, so `{conversation}` segments just work. If your
own app registers a competing `Route::bind('conversation', ...)` for something unrelated
elsewhere, that registration wins (whichever one runs last) — rename your route segment
in that case rather than fighting over the same parameter name.

## Human tool approval

`laravel/ai` lets a tool require a human decision before it runs. Mark one by
implementing `Approvable` and using the `InteractsWithApprovals` concern, then calling
`requireApproval()`:

```php
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;

class UpdateMeetingTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct()
    {
        $this->requireApproval('This changes a meeting record.');
    }

    // ...description(), handle(), schema()
}
```

When the agent calls that tool, the run **pauses** instead of executing it. The turn ends
in an `AWAITING_APPROVAL` state (rather than `COMPLETE`), and `components.chat.conversation`
renders an inline prompt beneath the assistant message — the tool name, the reason, and the
arguments the model wants to pass — with **Approve** and **Reject** buttons. When a single
turn pauses on more than one call, **Approve all** / **Reject all** are offered too, and a
mixed set is submitted once every call has a decision. The composer is disabled until the
pause is resolved.

Resolving dispatches a fresh turn that resumes the paused one: approved calls run and the
agent continues; rejected calls are reported back to the model as denied so it can carry on
without them. Nothing extra is persisted — this builds entirely on `laravel/ai`'s own
`approval_state` column on the messages table.

Resolving an approval is authorized by the same `sendMessage` policy ability as sending a
message. Each pause and resolution is also recorded as a `tool.approval_requested` /
`tool.approval_resolved` trace event, visible under "show thoughts".

> Only approve / reject are surfaced for now. `laravel/ai`'s `Decision::edit()` — approving
> a call with modified arguments — isn't wired to the UI yet.

## Trace correlation — important if you extend this package

Which conversation/turn is "in flight" is tracked via Laravel's `Context` facade, not
stored on the agent object — the agent binding is **not** a singleton. If you add code
that captures more trace data, read these `Context` keys rather than reaching for agent
identity:

| Key | Set by | Meaning |
|---|---|---|
| `ai-kit.conversation_id` / `ai-kit.turn_id` | `ProcessChatMessage::handle()`, once at the top | Which conversation/turn is in flight. Every capture listener below is a no-op unless `conversation_id` is present. |
| `ai-kit.tool_source` | `CaptureToolInvoking`, for the duration of that tool's `handle()` call | Attributes any HTTP call captured during that window to the tool rather than to the LLM provider — see `payload['source']` on an `http.exchange` event. |
| `ai-kit.tool_invocation_id` | `CaptureToolInvoking`, for the duration of that tool's `handle()` call | Correlates an `http.exchange` event back to the specific `tool.invoked` event it belongs to, so the UI can nest it under the right tool call even when the same tool runs more than once in a turn. |
| `ai-kit.pending_http_request` | The request-side HTTP middleware, cleared by the response-side middleware | Correlates a request with its response into one `http.exchange` event. Assumes calls through `Http::` happen one at a time; a concurrent caller (e.g. `Http::pool()`) would need its own correlation id instead. |

## Extension points

- Override `Smwks\LaravelAiKit\Chat\Policies\ConversationPolicy::viewThoughts()` (or swap
  in your own policy via `Gate::policy(Conversation::class, ...)`) to restrict who can
  see the "show thoughts" trace inspector — it defaults to the same rule as `view()`.
  This is checked in addition to, not instead of, the `showThoughts` component prop.
- Publish views (`--tag=ai-kit-chat-views`) and override
  `components/chat/partials/thought-details/generic.blade.php` to render domain-specific
  structured-output payloads.
- Give a specific tool its own "show thoughts" detail view via `config('ai-kit.chat.tool_views')`,
  keyed by the tool name found in a `tool.invoked` event's `payload['tool']`:

  ```php
  // config/ai-kit.php
  'chat' => [
      'tool_views' => [
          'weather' => 'chat.tools.weather-details',
      ],
  ],
  ```

  The view receives an `event` prop (a `ConversationEvent`). Tools not listed here keep
  rendering through the package's own generic tool partial — no need to publish or fork
  anything just to add one tool's view.
- Give a tool its own "thinking" status message by implementing
  `Smwks\LaravelAiKit\Turns\Contracts\HasStatusMessage`:

  ```php
  use Smwks\LaravelAiKit\Turns\Contracts\HasStatusMessage;

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
  broadcasting, and per-conversation rating/notes.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT — see [LICENSE.md](LICENSE.md).

## Why this package needs its own tables

`laravel/ai`'s `agent_conversations` / `agent_conversation_messages` tables model a
conversation as a sequence of *finished* messages — a row appears only once the agent has
actually replied. That's sufficient for `laravel/ai` itself, but not for a chat UI that
has to render *while* a reply is still being generated in a queued job.

**`agent_conversation_events`** is the obvious one: `laravel/ai` has nowhere to put trace
data at all. LLM requests/responses, tool invocations, and raw HTTP exchanges are the
entire data source behind the "show thoughts" panel, and none of it overlaps with
anything `laravel/ai` stores.

**`agent_conversation_turns`** is less obvious, since on the surface it looks like it
duplicates `agent_conversation_messages`. One row is created *before* the agent runs, and
it exists to answer questions the messages table structurally can't:

- **Is this message still being processed?** Sending a message dispatches
  `ProcessChatMessage` to a queue and returns immediately — the request that showed the
  "thinking" indicator has already ended. Nothing durable would otherwise exist for the
  next poll to check, since no assistant message row exists yet to look at.
- **What failed, if the queue worker died?** If the job throws, `agent_conversation_messages`
  gets no row and no error — a turn's `status` (`Pending` → `Processing` → `Complete` or
  `Failed`) is the only place that failure is ever recorded.
- **Which trace events belong to which reply?** Every `agent_conversation_events` row is
  tagged with a `turn_id`. `laravel/ai`'s own timeline has no equivalent of "the
  interaction that produced this one reply," so without a turn to key on, the trace
  inspector's per-message "show thoughts" toggle would have nothing to group by.
- **Resuming after a page reload mid-reply.** On mount, `chat.conversation` looks up the
  conversation's most recent non-final turn to decide whether it should still be polling
  — there's no other row it could look up to reconstruct that state.
