# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## What this is

`smwks/laravel-ai-kit` — a Composer library (not an app) providing a turn/event data model
plus a Livewire chat UI and "show thoughts" trace inspector for
[`laravel/ai`](https://github.com/laravel/ai) agents. PSR-4: `Smwks\LaravelAiKit\` → `src/`
(split into `Turns/` — models, event-capture listeners, `QueryTracker` — and `Chat/` — the
job, policy — plus top-level `Console/Commands/` and `Testbench/` shared by both),
`Smwks\LaravelAiKit\Tests\` → `tests/`. Auto-discovered service provider:
`LaravelAiKitServiceProvider`, which registers both modules unconditionally — there are no
feature flags; requiring the package activates turns, and additionally wiring your own
route to a chat component activates chat (the package owns no routes itself).

## Commands

```bash
composer install                       # install deps (CI uses `composer update`)
vendor/bin/pest                        # run the whole suite
vendor/bin/pest tests/Feature/ChatConversationComponentTest.php   # one file
vendor/bin/pest --filter='resumes a paused turn'                  # one test by description
```

There is no lint/format step configured (no Pint config) and no JS build — the JavaScript in
`resources/js/` is vendored, pre-built, and committed. Tests run via Orchestra Testbench with an
in-memory SQLite DB and `sync` queue; `tests/TestCase.php` boots `AiServiceProvider`,
`LivewireServiceProvider`, and this package's provider, and loads `laravel/ai`'s migrations plus
this package's own. CI (`.github/workflows/tests.yml`) runs PHP 8.4 / 8.5 against **Laravel 13 only** —
Laravel 12 is allowed by `composer.json` but currently untestable due to a `symfony/process`
version conflict between `pestphp/pest ^5` and the Laravel-12 line of `orchestra/testbench`.

## Architecture

### Components — presentation only, no routes

Three single-file Livewire class components live in `resources/views/components/chat/`
(`new`, `history`, `conversation`) and are exposed under the `ai-kit::components.chat.*`
namespace via `Livewire::addNamespace(...)` in the provider. The package **registers no routes and
owns no page chrome** — the consuming app embeds these components in pages it owns (plain Livewire
pages or Filament panel pages) and handles navigation itself. `new` and `history` emit browser
events (`ai-kit-conversation-started`, `ai-kit-conversation-selected`) rather than
redirecting. All three call `Auth::user()` and `abort(403)` for guests — the host must enforce auth.

### Data model — builds on `laravel/ai`, plus two owned tables

This package does not store conversations or messages; it uses `laravel/ai`'s
`agent_conversations` / `agent_conversation_messages` directly. It adds two tables (names come from
`config('ai-kit.turns.tables.*')`; models `ConversationTurn` / `ConversationEvent` have UUID7 string
PKs and `$guarded = []`):

- **`agent_conversation_turns`** — one row per user prompt, created *before* the agent runs.
  Carries lifecycle `status` (`ConversationTurnStatus`: `Pending → Processing → Complete | Failed |
  AwaitingApproval`). Exists because the messages table only holds *finished* messages, so it is the
  only place "still processing", "the job died", "which events belong to this reply", and
  "resume after a mid-reply page reload" can be answered. See README's "Why this package needs its
  own tables".
- **`agent_conversation_events`** — the trace log behind "show thoughts". `event_type` is one of
  `llm.request`, `llm.response`, `tool.invoking`, `tool.invoked`, `tool.approval_requested`,
  `tool.approval_resolved`, `http.exchange`, `error`; `payload` is JSON. `tool.invoking` is
  transient — it only feeds the live "thinking…" status string and is filtered out of the permanent
  trace view once `tool.invoked` lands.

### Async message flow

`conversation` component `sendMessage()` → create a `Pending` `ConversationTurn` →
dispatch `ProcessChatMessage` job → return immediately. The component then polls
`checkTurnStatus` via `wire:poll.1000ms` until the turn `isSettled()`. The job sets the two
`Context` keys, calls `$agent->continue($conversationId)->prompt($message)`, and updates the turn
status (`AwaitingApproval` if the response has pending approvals, else `Complete`; `Failed` on
throw, then re-throws).

### Trace capture — correlate via `Context`, never agent identity

The provider registers six listeners on `laravel/ai` events plus global `Http::` request/response
middleware. **Every capture listener is a no-op unless `Context::has('ai-kit.conversation_id')`.**
The agent container binding is **not** a singleton, so in-flight identity is tracked through the
`Context` facade. If you add trace capture, read these keys — don't reach for the agent object:

| Key | Set by | Purpose |
|---|---|---|
| `ai-kit.conversation_id` / `.turn_id` | `ProcessChatMessage::handle()`, once at top | what is in flight; gate for every listener |
| `ai-kit.tool_source` | `CaptureToolInvoking`, for the tool's `handle()` window | attributes an `http.exchange` to a tool vs. the provider |
| `ai-kit.tool_invocation_id` | `CaptureToolInvoking`, same window | correlates an `http.exchange` to its specific `tool.invoked` |
| `ai-kit.pending_http_request` | request middleware, cleared by response middleware | joins one request+response into one `http.exchange` event (assumes serial `Http::` calls) |

HTTP middleware strips `authorization` / `x-api-key` / `cookie` / `set-cookie` headers before persisting.

`reorderEventsForDisplay()` (in `conversation.blade.php`) defers each `http.exchange` that has a
`tool_invocation_id` until its parent `tool.invoked` renders, so a tool's own HTTP calls nest under
that tool box rather than appearing chronologically above it. `eventIndentClass()` sets visual depth.

### QueryTracker

Singleton service (`$this->app->singleton(...)`). Registers a **single** `QueryExecuted` listener on
the instance that first constructs — it *must* stay a singleton or a second instance would silently
receive no events. `CaptureToolInvoking` starts tracking, `CaptureToolInvoked` stops it and folds
the captured SQL into the `tool.invoked` payload's `sql_queries`. Writes to this package's own
turns/events tables are excluded from that trace.

### Human tool approval

Builds entirely on `laravel/ai`'s `Approvable` contract and `approval_state` column — nothing extra
persisted. A tool calling `requireApproval()` pauses the run; the turn ends `AwaitingApproval`. The
`conversation` component derives `pendingApprovals` (a `#[Computed]`) from the paused assistant
message's `approval_state['pending']`, minus any call already answered on a later row, and renders
inline Approve/Reject (plus Approve/Reject all for multi-call pauses). Resolving marks the paused
turn `Complete`, creates a fresh `Pending` turn, and dispatches `ProcessChatMessage` with a
`Laravel\Ai\Approvals\Decisions` as the message (the job's `$message` param is `string|Decisions`).
Gated by the same `sendMessage` policy ability as sending.

### Authorization

`ConversationPolicy` (`view` / `sendMessage` / `viewThoughts`) is registered for
`Laravel\Ai\Models\Conversation` via `Gate::policy(...)`. All three default to "requesting user is
the conversation's participant". `viewThoughts` is ANDed with the `showThoughts` component prop.
Override `viewThoughts` (or swap the whole policy) to restrict the trace inspector further.

### Route model binding workaround

The provider registers an explicit `Route::bind('conversation', ...)` (resolve by key only) because
Livewire hands a serialized property back to Laravel's *implicit* binding, which then queries every
column and 404s. A host app registering its own `Route::bind('conversation', ...)` later wins.

### Module boundary

`Turns` (`src/Turns/`) never depends on `Chat` (`src/Chat/`) — including
`Contracts\HasStatusMessage`, which lives under `Turns\Contracts` because it's consumed by
`Turns\Listeners\CaptureToolInvoking`, even though its only purpose is feeding the chat UI's
"thinking" status string.

## Config (`config/ai-kit.php`)

- `turns.tables` — names for the two owned tables.
- `chat.tool_views` — map a tool name (from `tool.invoked` payload `['tool']`) to a Blade view for its detail panel; unlisted tools use the generic partial.
- `chat.queue` — `connection` / `name` for `ProcessChatMessage`.

There is no `chat.agent` config key — the `agent` prop on `conversation` is required, not
optional. The package does **not** persist which agent a conversation used — routing must
consistently pair a conversation with its agent.

## Conventions

- `src/Testbench/` holds runnable sample agents/tools (`EchoAgent`, `ApprovalTool`, `HttpCallingTool`,
  `StatusMessageTool`, …) — they are both the zero-config defaults *and* the test fixtures. Keep them working.
- Extension without forking: implement `Contracts\HasStatusMessage` for a custom "thinking" string;
  use `tool_views` config for a custom detail panel; publish views (`--tag=ai-kit-chat-views`) only to
  override the generic partials.
- Every structural element in the Blade carries a `data-ai-kit="<role>"` hook — preserve these
  when editing views; they are the package's public theming contract.
- Publish tags: `ai-kit-config`, `ai-kit-migrations`, `ai-kit-chat-assets`, `ai-kit-chat-views`.
- `InstallCommand` (`php artisan ai-kit:install`) verifies both `laravel/ai`'s own tables and
  this package's turns/events tables exist; it runs nothing.
