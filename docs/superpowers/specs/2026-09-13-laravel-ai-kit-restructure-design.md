# Rename to `smwks/laravel-ai-kit` and split into turns + chat modules

Date: 2026-09-13
Status: approved, ready for implementation planning

## Context

`smwks/laravel-ai-chat-ui` currently ships one undifferentiated feature: a Livewire chat UI
that happens to be built on top of a turn/event data model (`agent_conversation_turns` /
`agent_conversation_events`) used for durable message status and the "show thoughts" trace
inspector. Everything is wired unconditionally in one service provider.

The goal is to reposition this as `smwks/laravel-ai-kit`, a small component library for
`laravel/ai` development, where the turn/event data model and the chat UI are recognizable as
two separable things — a consumer building their own UI on top of `laravel/ai` should be able to
get turn/event modeling without also getting Livewire chat components.

There are no external consumers of the package today, so this is a clean-break rename: no
backward-compatible aliases, no deprecated shim package.

## Decisions

### 1. Identity

- Composer package: `smwks/laravel-ai-kit`
- Root namespace: `Smwks\LaravelAiKit\` (tests: `Smwks\LaravelAiKit\Tests\`)
- Service provider: `LaravelAiKitServiceProvider`
- Config file: `config/ai-kit.php`
- Blade view / Livewire component namespace: `ai-kit::components.chat.*`
- Console command: `ai-kit:install`
- Publish tags: `ai-kit-config`, `ai-kit-migrations`, `ai-kit-chat-assets`, `ai-kit-chat-views`

### 2. No feature flags — both modules register unconditionally

Turns (models, event-capture listeners, `QueryTracker`) and chat (Livewire components, views,
policy gate, route-model-binding workaround) both register unconditionally in
`LaravelAiKitServiceProvider::boot()`. There is no `LaravelAiKit::useTurns()` /
`::useChat()` API and no config-driven feature toggle.

This replaces the original idea of explicit opt-in flags, arrived at through the following
reasoning during design:

- **Turns doesn't need a flag.** The six event-capture listeners are already no-ops unless
  `Context::has('ai-kit.conversation_id')` — registering them costs nothing when unused.
  "Opting in" to turns is naturally expressed by *using* it: either through the bundled chat
  job, or by a consumer setting the same `Context` keys around their own agent calls. A
  boolean flag would gate wiring, not behavior, and would add an ordering hazard (host must
  call it before the package's own `boot()` acts on it) for no real benefit.
- **Chat doesn't need a flag either.** The package registers no routes and owns no page
  chrome (existing architecture) — a consumer must already write their own route pointing at
  a page that embeds `<livewire:ai-kit::components.chat.*>` before anything is reachable.
  That requirement already *is* the opt-in signal. An additional `useChat()` call would be
  redundant ceremony on top of a route the host must write anyway.
- **The one real risk (`Route::bind('conversation', ...)`) isn't new.** This workaround
  (documented in the "Route model binding workaround" section) is a global override of how
  Laravel resolves any `{conversation}` route parameter, needed because Livewire hands
  serialized properties back to implicit binding. Registering it unconditionally means a host
  with an unrelated `{conversation}` parameter elsewhere in their app could see it hijacked —
  but this risk exists identically whether or not a flag gates it, since the fix has always
  been "register your own `Route::bind('conversation', ...)` afterward; last registration
  wins." This stays a documented limitation, not a reason to add a flag.

Net effect: requiring the package makes turns live; additionally wiring a route to the chat
components makes chat live. No code-level toggle anywhere.

### 3. Directory layout

```
src/
  LaravelAiKitServiceProvider.php
  Console/Commands/InstallCommand.php
  Turns/
    Models/ConversationTurn.php
    Models/ConversationEvent.php
    Enums/ConversationTurnStatus.php
    Listeners/CaptureAgentRequest.php
    Listeners/CaptureAgentResponse.php
    Listeners/CaptureToolApprovalRequested.php
    Listeners/CaptureToolApprovalResolved.php
    Listeners/CaptureToolInvoked.php
    Listeners/CaptureToolInvoking.php
    Services/QueryTracker.php
  Chat/
    Jobs/ProcessChatMessage.php
    Policies/ConversationPolicy.php
    Contracts/HasStatusMessage.php
  Testbench/
    (unchanged — shared fixtures used by both turns and chat tests)

resources/
  views/components/chat/...   (unchanged tree — chat is the only view layer; turns has none)
  js/, css/                    (unchanged, published under ai-kit-chat-assets)

database/migrations/           (unchanged filenames/content, published under ai-kit-migrations)

config/ai-kit.php
```

`Console/Commands/` and `Testbench/` stay top-level since they serve both modules.

### 4. Config shape

```php
return [
    'turns' => [
        'tables' => [
            'turns' => 'agent_conversation_turns',
            'events' => 'agent_conversation_events',
        ],
    ],

    'chat' => [
        'tool_views' => [],
        'queue' => [
            'connection' => null,
            'name' => 'default',
        ],
    ],
];
```

No `chat.agent` key — see decision 5.

### 5. `agent` becomes a required component prop, not a config default

Today `conversation`'s `public ?string $agent = null` falls back to
`config('ai-chat-ui.agent')`, which defaults to the bundled `EchoAgent`. Since a consumer
already must write the route/page that embeds `conversation` (decision 2), requiring
`agent` explicitly there is no extra burden and removes an implicit app-wide default that
doesn't fit a multi-agent "kit" framing.

- `conversation`'s `agent` prop becomes non-nullable/required: `public string $agent;`
- `config('ai-kit.chat.agent')` is removed entirely.
- `Testbench\EchoAgent` remains as a test fixture and a README quick-start example, but is no
  longer a zero-config default reachable without passing `agent` explicitly.
- `new` and `history` are unaffected — they don't reference an agent (conversations don't
  persist which agent they used, per existing architecture).

### 6. Renames (mechanical, apply throughout)

- `ai-chat-ui` → `ai-kit` in every string occurrence: `Context` key prefixes
  (`ai-chat-ui.conversation_id` → `ai-kit.conversation_id`, and likewise `.turn_id`,
  `.tool_source`, `.tool_invocation_id`, `.pending_http_request`), the Blade view/Livewire
  namespace, `public_path('vendor/ai-chat-ui/...')` asset paths, and every
  `data-ai-chat-ui="<role>"` Blade hook (→ `data-ai-kit="<role>"`) — these hooks are the
  package's documented public theming contract and move together with the rename rather than
  being left aliased.
- `composer.json` (`name`, `description`, PSR-4 autoload, `extra.laravel.providers`).
- `README.md`, `CLAUDE.md`, `AGENTS.md` updated to describe the new name, namespace,
  directory split, and the "no feature flags, unconditional registration" architecture
  (replacing any prior/planned language about opt-in flags).

### 7. `InstallCommand`

Renamed to `ai-kit:install`. Its existing responsibility (verify tables exist; run nothing)
is unchanged in kind, just widened: it already checks `laravel/ai`'s own tables, and now
also unconditionally checks the turns tables (`agent_conversation_turns` /
`agent_conversation_events`, from `config('ai-kit.turns.tables')`) exist — unconditional
because turns is always active per decision 2, not gated behind any flag. No runtime
`Schema::hasTable` guard is added elsewhere; a missing-table error at actual query time is
treated as an ordinary "you forgot to migrate" failure, same as any other Laravel package
dependency.

## Out of scope

- Any backward-compatibility shim, alias, or deprecated meta-package for the old
  `smwks/laravel-ai-chat-ui` name — confirmed no external consumers exist yet.
- Any additional "kit" features beyond turns/events and chat — not requested, not designed
  here (YAGNI).
- Splitting `resources/views` into a separate turns view namespace — turns has no view layer.
