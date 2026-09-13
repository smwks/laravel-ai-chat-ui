# Laravel AI Kit Restructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename `smwks/laravel-ai-chat-ui` to `smwks/laravel-ai-kit`, split its code into a `Turns` module (data model + trace capture, always active) and a `Chat` module (Livewire UI, always active but requires the host's own route), and make the `conversation` component's `agent` prop required instead of falling back to a config default.

**Architecture:** One service provider (renamed `LaravelAiKitServiceProvider`) still registers everything unconditionally — no feature flags were introduced (see the spec's "Decisions §2" for why). `src/` splits into `src/Turns/{Models,Enums,Listeners,Services,Contracts}` and `src/Chat/{Jobs,Policies}`; `src/Console/Commands` and `src/Testbench` stay top-level since both modules use them. `config/ai-chat-ui.php` becomes `config/ai-kit.php` with `turns` and `chat` sections. Every `ai-chat-ui`-prefixed runtime identifier (Context keys, the Blade/Livewire view namespace, publish tags, the artisan command name, dispatched event names, a JS function name, a CSS/data-attribute prefix, a cache-key prefix) is renamed to `ai-kit`.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Livewire 4.1, Pest 5, Orchestra Testbench.

**Spec:** `docs/superpowers/specs/2026-09-13-laravel-ai-kit-restructure-design.md`

## Global Constraints

- No backward-compatibility shim, alias, class_alias, or deprecated meta-package for the old `smwks/laravel-ai-chat-ui` name or `Smwks\LaravelAiChatUi\` namespace — confirmed no external consumers exist yet.
- No feature flags anywhere (no `LaravelAiKit::useTurns()`/`::useChat()`, no config-driven module toggle) — both modules register unconditionally in the service provider.
- `HasStatusMessage` lives under `Turns\Contracts`, **not** `Chat\Contracts` as the spec's illustrative directory preview showed — it's consumed by `Turns\Listeners\CaptureToolInvoking`, and Turns must not depend on Chat (see Task 1, deviation note).
- `agent` on the `conversation` component becomes a required, non-nullable `public string $agent` — no `config('ai-kit.chat.agent')` fallback survives past Task 3.
- Run `vendor/bin/pest` (whole suite) at the end of every task and confirm it is green before moving to the next task.

---

## Task 1: Composer identity + directory/namespace restructure

Pure mechanical rename: package name, root namespace, provider class name, and moving six classes into `Turns/` and two into `Chat/`. No runtime string identifiers (config keys, Context keys, view namespace, etc.) change in this task — that's Task 2. No behavior changes at all in this task.

**Files:**
- Modify: `composer.json`
- Move + modify (namespace only): all files listed in the "File moves" table below
- Modify (namespace/`use` updates only, no move): every other `.php` file under `src/` and `tests/` that references a moved class

**Interfaces:**
- Produces: `Smwks\LaravelAiKit\LaravelAiKitServiceProvider`, `Smwks\LaravelAiKit\Turns\Models\{ConversationTurn,ConversationEvent}`, `Smwks\LaravelAiKit\Turns\Enums\ConversationTurnStatus`, `Smwks\LaravelAiKit\Turns\Listeners\{CaptureAgentRequest,CaptureAgentResponse,CaptureToolApprovalRequested,CaptureToolApprovalResolved,CaptureToolInvoked,CaptureToolInvoking}`, `Smwks\LaravelAiKit\Turns\Services\QueryTracker`, `Smwks\LaravelAiKit\Turns\Contracts\HasStatusMessage`, `Smwks\LaravelAiKit\Chat\Jobs\ProcessChatMessage`, `Smwks\LaravelAiKit\Chat\Policies\ConversationPolicy`, `Smwks\LaravelAiKit\Console\Commands\InstallCommand`, `Smwks\LaravelAiKit\Testbench\*`, `Smwks\LaravelAiKit\Tests\TestCase`. Every later task's code references these exact FQCNs.

- [ ] **Step 1: Update `composer.json` identity**

Edit `composer.json`:
- `"name": "smwks/laravel-ai-chat-ui"` → `"name": "smwks/laravel-ai-kit"`
- `"description": "Livewire chat UI and trace inspector for laravel/ai agents."` → `"description": "A component kit (turn/event data model + Livewire chat UI) for laravel/ai agents."`
- `"Smwks\\LaravelAiChatUi\\": "src/"` → `"Smwks\\LaravelAiKit\\": "src/"`
- `"Smwks\\LaravelAiChatUi\\Tests\\": "tests/"` → `"Smwks\\LaravelAiKit\\Tests\\": "tests/"`
- `"Smwks\\LaravelAiChatUi\\LaravelAiChatUiServiceProvider"` → `"Smwks\\LaravelAiKit\\LaravelAiKitServiceProvider"`

- [ ] **Step 2: Move files with `git mv`**

Run, from the repo root:

```bash
git mv src/LaravelAiChatUiServiceProvider.php src/LaravelAiKitServiceProvider.php

mkdir -p src/Turns/Models src/Turns/Enums src/Turns/Listeners src/Turns/Services src/Turns/Contracts src/Chat/Jobs src/Chat/Policies

git mv src/Models/ConversationTurn.php src/Turns/Models/ConversationTurn.php
git mv src/Models/ConversationEvent.php src/Turns/Models/ConversationEvent.php
git mv src/Enums/ConversationTurnStatus.php src/Turns/Enums/ConversationTurnStatus.php
git mv src/Listeners/CaptureAgentRequest.php src/Turns/Listeners/CaptureAgentRequest.php
git mv src/Listeners/CaptureAgentResponse.php src/Turns/Listeners/CaptureAgentResponse.php
git mv src/Listeners/CaptureToolApprovalRequested.php src/Turns/Listeners/CaptureToolApprovalRequested.php
git mv src/Listeners/CaptureToolApprovalResolved.php src/Turns/Listeners/CaptureToolApprovalResolved.php
git mv src/Listeners/CaptureToolInvoked.php src/Turns/Listeners/CaptureToolInvoked.php
git mv src/Listeners/CaptureToolInvoking.php src/Turns/Listeners/CaptureToolInvoking.php
git mv src/Services/QueryTracker.php src/Turns/Services/QueryTracker.php
git mv src/Contracts/HasStatusMessage.php src/Turns/Contracts/HasStatusMessage.php
git mv src/Jobs/ProcessChatMessage.php src/Chat/Jobs/ProcessChatMessage.php
git mv src/Policies/ConversationPolicy.php src/Chat/Policies/ConversationPolicy.php

rmdir src/Models src/Enums src/Listeners src/Services src/Contracts src/Jobs src/Policies
```

(`src/Console/Commands/` and `src/Testbench/` are not moved — they stay top-level.)

- [ ] **Step 3: Rewrite each moved file's `namespace` line**

| File | Old `namespace` line | New `namespace` line |
|---|---|---|
| `src/LaravelAiKitServiceProvider.php` | `namespace Smwks\LaravelAiChatUi;` | `namespace Smwks\LaravelAiKit;` |
| `src/Turns/Models/ConversationTurn.php`, `src/Turns/Models/ConversationEvent.php` | `namespace Smwks\LaravelAiChatUi\Models;` | `namespace Smwks\LaravelAiKit\Turns\Models;` |
| `src/Turns/Enums/ConversationTurnStatus.php` | `namespace Smwks\LaravelAiChatUi\Enums;` | `namespace Smwks\LaravelAiKit\Turns\Enums;` |
| `src/Turns/Listeners/*.php` (all 6) | `namespace Smwks\LaravelAiChatUi\Listeners;` | `namespace Smwks\LaravelAiKit\Turns\Listeners;` |
| `src/Turns/Services/QueryTracker.php` | `namespace Smwks\LaravelAiChatUi\Services;` | `namespace Smwks\LaravelAiKit\Turns\Services;` |
| `src/Turns/Contracts/HasStatusMessage.php` | `namespace Smwks\LaravelAiChatUi\Contracts;` | `namespace Smwks\LaravelAiKit\Turns\Contracts;` |
| `src/Chat/Jobs/ProcessChatMessage.php` | `namespace Smwks\LaravelAiChatUi\Jobs;` | `namespace Smwks\LaravelAiKit\Chat\Jobs;` |
| `src/Chat/Policies/ConversationPolicy.php` | `namespace Smwks\LaravelAiChatUi\Policies;` | `namespace Smwks\LaravelAiKit\Chat\Policies;` |
| `src/Console/Commands/InstallCommand.php` | `namespace Smwks\LaravelAiChatUi\Console\Commands;` | `namespace Smwks\LaravelAiKit\Console\Commands;` |
| `src/Testbench/*.php` (all 9) | `namespace Smwks\LaravelAiChatUi\Testbench;` | `namespace Smwks\LaravelAiKit\Testbench;` |
| `tests/TestCase.php` | `namespace Smwks\LaravelAiChatUi\Tests;` | `namespace Smwks\LaravelAiKit\Tests;` |

Note the deviation from the spec's illustrative preview: `HasStatusMessage` goes under `Turns\Contracts`, not `Chat\Contracts` — it's consumed by `Turns\Listeners\CaptureToolInvoking` to fill the `tool.invoking` event payload, so putting it under `Chat` would make the Turns module depend on the Chat namespace, backwards from the intended module boundary.

- [ ] **Step 4: Rewrite every `use`/FQCN reference across `src/` and `tests/`**

Run these from the repo root (order matters — most-specific patterns first, generic catch-all last):

```bash
grep -rl 'Smwks\\LaravelAiChatUi\|LaravelAiChatUiServiceProvider' src tests | xargs perl -pi -e '
  s/LaravelAiChatUiServiceProvider/LaravelAiKitServiceProvider/g;
  s/Smwks\\LaravelAiChatUi\\Models\\/Smwks\\LaravelAiKit\\Turns\\Models\\/g;
  s/Smwks\\LaravelAiChatUi\\Enums\\/Smwks\\LaravelAiKit\\Turns\\Enums\\/g;
  s/Smwks\\LaravelAiChatUi\\Listeners\\/Smwks\\LaravelAiKit\\Turns\\Listeners\\/g;
  s/Smwks\\LaravelAiChatUi\\Services\\/Smwks\\LaravelAiKit\\Turns\\Services\\/g;
  s/Smwks\\LaravelAiChatUi\\Contracts\\/Smwks\\LaravelAiKit\\Turns\\Contracts\\/g;
  s/Smwks\\LaravelAiChatUi\\Jobs\\/Smwks\\LaravelAiKit\\Chat\\Jobs\\/g;
  s/Smwks\\LaravelAiChatUi\\Policies\\/Smwks\\LaravelAiKit\\Chat\\Policies\\/g;
  s/Smwks\\LaravelAiChatUi/Smwks\\LaravelAiKit/g;
'
```

This one pass covers: every `src/` file's own `use` imports of moved classes, `src/LaravelAiKitServiceProvider.php`'s imports and its six `Event::listen(...)` class references, `resources/views/components/chat/conversation.blade.php` and `history.blade.php`'s `use` statements, and every test file's `use` imports plus `tests/Pest.php`'s `use Smwks\LaravelAiChatUi\Tests\TestCase;`.

- [ ] **Step 5: Verify autoloading and run the suite**

```bash
composer dump-autoload
vendor/bin/pest
```

Expected: full suite green. Nothing in this task changed config keys, Context keys, the view namespace, or any other runtime string, so behavior is unchanged — only symbols moved.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Rename namespace to Smwks\LaravelAiKit and split src/ into Turns/Chat

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01GCYzk1TaNJwyTRZfUW66Ha
EOF
)"
```

---

## Task 2: Runtime string-identifier rename

Renames every `ai-chat-ui`-prefixed runtime string to `ai-kit`, and restructures `config/ai-chat-ui.php` into `config/ai-kit.php` with `turns`/`chat` sections (the `chat.agent` key is kept for now — Task 3 removes it). No behavior changes beyond the identifiers themselves.

**Files:**
- Create: `config/ai-kit.php`
- Delete: `config/ai-chat-ui.php`
- Modify: `src/LaravelAiKitServiceProvider.php`, `src/Turns/Models/ConversationTurn.php`, `src/Turns/Models/ConversationEvent.php`, `src/Turns/Listeners/*.php` (all 6), `src/Turns/Services/QueryTracker.php`, `src/Chat/Jobs/ProcessChatMessage.php`, `src/Console/Commands/InstallCommand.php`, `database/migrations/2026_08_23_000001_create_agent_conversation_turns_table.php`, `database/migrations/2026_08_23_000002_create_agent_conversation_events_table.php`, `resources/views/components/chat/conversation.blade.php`, `resources/views/components/chat/new.blade.php`, `resources/views/components/chat/history.blade.php`, `resources/views/components/chat/partials/copyable-id.blade.php` (no changes needed — verify), `resources/views/components/chat/partials/json-viewer.blade.php`, `resources/views/components/chat/partials/json-viewer-node.blade.php`, `resources/views/components/chat/partials/thought-details/*.blade.php` (all 7), `resources/js/json-tree-search.js`, `resources/css/reply-body.css`, and every file in `tests/Feature/*.php` that references any of these strings.

**Interfaces:**
- Consumes: FQCNs produced by Task 1.
- Produces: config keys `ai-kit.turns.tables.{turns,events}`, `ai-kit.chat.{agent,tool_views,queue.connection,queue.name}`; Context keys `ai-kit.{conversation_id,turn_id,tool_source,tool_invocation_id,pending_http_request}`; view/Livewire namespace `ai-kit::`; publish tags `ai-kit-config`, `ai-kit-migrations`, `ai-kit-chat-assets`, `ai-kit-chat-views`; artisan command `ai-kit:install`; dispatched events `ai-kit-conversation-started`, `ai-kit-conversation-selected`; JS global `window.aiKitJsonSearch`; data attributes `data-ai-kit`/`data-ai-kit-role`; cache key prefix `ai-kit.rendered-markdown.`.

- [ ] **Step 1: Create `config/ai-kit.php` and delete the old config file**

Write `config/ai-kit.php`:

```php
<?php

use Smwks\LaravelAiKit\Testbench\EchoAgent;

return [

    'turns' => [
        'tables' => [
            'turns' => 'agent_conversation_turns',
            'events' => 'agent_conversation_events',
        ],
    ],

    'chat' => [
        'agent' => EchoAgent::class,

        /*
        |----------------------------------------------------------------
        | Tool detail views
        |----------------------------------------------------------------
        |
        | Map a tool name (the value found in a "tool.invoked" event's
        | payload['tool']) to a Blade view name to render its "show
        | thoughts" detail panel with. Any tool not listed here falls
        | back to the package's own generic tool partial.
        |
        | 'weather' => 'chat.tools.weather-details',
        |
        */

        'tool_views' => [],

        'queue' => [
            'connection' => null,
            'name' => 'default',
        ],
    ],

];
```

Delete `config/ai-chat-ui.php`.

- [ ] **Step 2: Update the service provider**

In `src/LaravelAiKitServiceProvider.php`:

- `mergeConfigFrom(__DIR__.'/../config/ai-chat-ui.php', 'ai-chat-ui')` → `mergeConfigFrom(__DIR__.'/../config/ai-kit.php', 'ai-kit')`
- `loadViewsFrom(__DIR__.'/../resources/views', 'ai-chat-ui')` → `loadViewsFrom(__DIR__.'/../resources/views', 'ai-kit')`
- `Livewire::addNamespace('ai-chat-ui', __DIR__.'/../resources/views');` → `Livewire::addNamespace('ai-kit', __DIR__.'/../resources/views');`
- `$this->publishes([__DIR__.'/../config/ai-chat-ui.php' => config_path('ai-chat-ui.php')], 'ai-chat-ui-config');` → `$this->publishes([__DIR__.'/../config/ai-kit.php' => config_path('ai-kit.php')], 'ai-kit-config');`
- The migrations `$this->publishes([...], 'ai-chat-ui-migrations')` block → tag `'ai-kit-migrations'` (migration filenames/paths unchanged)
- The assets `$this->publishes([...], 'ai-chat-ui-assets')` block, publishing to `public_path('vendor/ai-chat-ui/...')` → tag `'ai-kit-chat-assets'`, publishing to `public_path('vendor/ai-kit/...')` (three lines: `json-viewer.min.js`, `json-tree-search.js`, `reply-body.css`)
- The views `$this->publishes([...], 'ai-chat-ui-views')` block, publishing to `resource_path('views/vendor/ai-chat-ui')` → tag `'ai-kit-chat-views'`, publishing to `resource_path('views/vendor/ai-kit')`
- In `registerHttpCaptureMiddleware()`: both `Context::has('ai-chat-ui.conversation_id')` checks → `Context::has('ai-kit.conversation_id')`; `Context::add('ai-chat-ui.pending_http_request', ...)` and `Context::get('ai-chat-ui.pending_http_request')` / `Context::forget('ai-chat-ui.pending_http_request')` → `ai-kit.pending_http_request`; the `ConversationEvent::create([...])` call's `'conversation_id' => Context::get('ai-chat-ui.conversation_id')`, `'turn_id' => Context::get('ai-chat-ui.turn_id')`, and `Context::get('ai-chat-ui.tool_source', 'provider')` / `Context::get('ai-chat-ui.tool_invocation_id')` → all `ai-kit.*` equivalents.

- [ ] **Step 3: Update Turns models and migrations**

`src/Turns/Models/ConversationTurn.php`: `config('ai-chat-ui.tables.turns', 'agent_conversation_turns')` → `config('ai-kit.turns.tables.turns', 'agent_conversation_turns')`.

`src/Turns/Models/ConversationEvent.php`: `config('ai-chat-ui.tables.events', 'agent_conversation_events')` → `config('ai-kit.turns.tables.events', 'agent_conversation_events')`.

`database/migrations/2026_08_23_000001_create_agent_conversation_turns_table.php`: both `config('ai-chat-ui.tables.turns', 'agent_conversation_turns')` occurrences (in `up()` and `down()`) → `config('ai-kit.turns.tables.turns', 'agent_conversation_turns')`.

`database/migrations/2026_08_23_000002_create_agent_conversation_events_table.php`: both `config('ai-chat-ui.tables.events', 'agent_conversation_events')` occurrences → `config('ai-kit.turns.tables.events', 'agent_conversation_events')`.

- [ ] **Step 4: Update Turns listeners and QueryTracker**

In each of `src/Turns/Listeners/CaptureAgentRequest.php`, `CaptureAgentResponse.php`, `CaptureToolApprovalRequested.php`, `CaptureToolApprovalResolved.php`, `CaptureToolInvoked.php`, `CaptureToolInvoking.php`: every `Context::has('ai-chat-ui.conversation_id')`, `Context::get('ai-chat-ui.conversation_id')`, `Context::get('ai-chat-ui.turn_id')` → `ai-kit.conversation_id` / `ai-kit.turn_id`.

Additionally in `CaptureToolInvoked.php` and `CaptureToolInvoking.php`: `Context::forget('ai-chat-ui.tool_source')`, `Context::forget('ai-chat-ui.tool_invocation_id')`, `Context::add('ai-chat-ui.tool_source', ...)`, `Context::add('ai-chat-ui.tool_invocation_id', ...)` → `ai-kit.tool_source` / `ai-kit.tool_invocation_id`.

`src/Turns/Services/QueryTracker.php`: `config('ai-chat-ui.tables.events')` and `config('ai-chat-ui.tables.turns')` (in `isOwnLoggingQuery()`) → `config('ai-kit.turns.tables.events')` / `config('ai-kit.turns.tables.turns')`.

- [ ] **Step 5: Update the Chat job and install command**

`src/Chat/Jobs/ProcessChatMessage.php`: `config('ai-chat-ui.queue.connection')` → `config('ai-kit.chat.queue.connection')`; `config('ai-chat-ui.queue.name', 'default')` → `config('ai-kit.chat.queue.name', 'default')`; `Context::add('ai-chat-ui.conversation_id', ...)` / `Context::add('ai-chat-ui.turn_id', ...)` → `ai-kit.*`.

`src/Console/Commands/InstallCommand.php`: `protected $signature = 'ai-chat-ui:install';` → `'ai-kit:install'`; description string `"Verify laravel/ai's conversation tables exist before using ai-chat-ui"` → `"...using ai-kit"`; success message `"...ai-chat-ui is ready to use."` → `"...ai-kit is ready to use."`.

- [ ] **Step 6: Update the Blade views**

Across all of `resources/views/components/chat/conversation.blade.php`, `new.blade.php`, `history.blade.php`, and every file under `resources/views/components/chat/partials/` (`json-viewer.blade.php`, `json-viewer-node.blade.php`, `thought-details/_header.blade.php`, `thought-details/generic.blade.php`, `thought-details/http-exchange.blade.php`, `thought-details/llm-request.blade.php`, `thought-details/llm-response.blade.php`, `thought-details/tool.blade.php`):

```bash
grep -rl 'ai-chat-ui' resources/views | xargs perl -pi -e "s/ai-chat-ui::/ai-kit::/g"
```

This rewrites every `@include('ai-chat-ui::components.chat...')` call. It does **not** touch `data-ai-chat-ui` attributes (handled next) since the pattern requires the `::`.

Then, in `resources/views/components/chat/conversation.blade.php` specifically (the only file with these):
- `config("ai-chat-ui.tool_views.{$tool}")` → `config("ai-kit.chat.tool_views.{$tool}")`
- Every `data-ai-chat-ui="..."` and `data-ai-chat-ui-role="..."` attribute (root, header, thread, message, reply-body, composer, thoughts-toggle, thought-event, approval, approval-request, approval-approve, approval-reject) → `data-ai-kit="..."` / `data-ai-kit-role="..."`
- `asset('vendor/ai-chat-ui/json-viewer.min.js')`, `asset('vendor/ai-chat-ui/json-tree-search.js')`, `asset('vendor/ai-chat-ui/reply-body.css')` → `vendor/ai-kit/...`
- `Cache::rememberForever("ai-chat-ui.rendered-markdown.{$message->id}", ...)` → `"ai-kit.rendered-markdown.{$message->id}"`
- JS function `function scrollAiChatUiThreadToBottom()` (and its two `addEventListener` references + the trailing call) → `scrollAiKitThreadToBottom`
- `$el.querySelector('[data-ai-chat-ui="thread"]')` → `[data-ai-kit="thread"]`

In `resources/views/components/chat/new.blade.php` and `history.blade.php`:
- `data-ai-chat-ui="root"` / `data-ai-chat-ui="header"` → `data-ai-kit="..."`
- `$this->dispatch('ai-chat-ui-conversation-started', ...)` → `'ai-kit-conversation-started'`
- `$this->dispatch('ai-chat-ui-conversation-selected', ...)` → `'ai-kit-conversation-selected'`

In `resources/views/components/chat/partials/json-viewer.blade.php`:
- `x-on:input="window.aiChatUiJsonSearch($refs.tree, search)"` → `window.aiKitJsonSearch(...)`

- [ ] **Step 7: Update the JS and CSS assets**

`resources/js/json-tree-search.js`: the doc comment `Plain-text search over a rendered ai-chat-ui JSON tree` → `ai-kit`; `function aiChatUiJsonSearch(root, search)` → `function aiKitJsonSearch(root, search)`; `window.aiChatUiJsonSearch = aiChatUiJsonSearch;` → `window.aiKitJsonSearch = aiKitJsonSearch;`.

`resources/css/reply-body.css`: both doc-comment mentions (`data-ai-chat-ui="reply-body"`, `--tag=ai-chat-ui-assets`) and all 20 `[data-ai-chat-ui="reply-body"]` selectors → `data-ai-kit="reply-body"` / `--tag=ai-kit-chat-assets`. Run:

```bash
perl -pi -e 's/data-ai-chat-ui/data-ai-kit/g; s/ai-chat-ui-assets/ai-kit-chat-assets/g' resources/css/reply-body.css
```

- [ ] **Step 8: Update tests**

`tests/Feature/ConfigTest.php` — rewrite to:

```php
<?php

use Smwks\LaravelAiKit\Testbench\EchoAgent;

it('merges package defaults', function () {
    expect(config('ai-kit.turns.tables.turns'))->toBe('agent_conversation_turns');
    expect(config('ai-kit.turns.tables.events'))->toBe('agent_conversation_events');
    expect(config('ai-kit.chat.agent'))->toBe(EchoAgent::class);
    expect(config('ai-kit.chat.tool_views'))->toBe([]);
    expect(config('ai-kit.chat.queue.name'))->toBe('default');
});
```

`tests/Feature/InstallCommandTest.php` — both `$this->artisan('ai-chat-ui:install')` calls → `$this->artisan('ai-kit:install')`.

`tests/Feature/MigrationsTest.php` — no `ai-chat-ui` strings (only table names, which are unaffected) — verify no change needed.

Across `tests/Feature/ChatConversationComponentTest.php`, `ChatHistoryComponentTest.php`, `ChatNewComponentTest.php`, `ConversationRouteBindingTest.php`, `EchoAgentTest.php` (no `ai-chat-ui` strings here — verify no change needed), `EventListenersTest.php`, `HttpCaptureTest.php`, `JsonViewerTest.php`, `ProcessChatMessageTest.php`, `QueryTrackerTest.php` (no `ai-chat-ui` strings — verify), `ToolApprovalTest.php`:

```bash
grep -rl 'ai-chat-ui' tests/Feature | xargs perl -pi -e '
  s/ai-chat-ui::/ai-kit::/g;
  s/ai-chat-ui-conversation-started/ai-kit-conversation-started/g;
  s/ai-chat-ui-conversation-selected/ai-kit-conversation-selected/g;
  s/data-ai-chat-ui/data-ai-kit/g;
  s/ai-chat-ui\.conversation_id/ai-kit.conversation_id/g;
  s/ai-chat-ui\.turn_id/ai-kit.turn_id/g;
  s/ai-chat-ui\.rendered-markdown\./ai-kit.rendered-markdown./g;
'
```

Then, by hand in `ChatConversationComponentTest.php`: `config('ai-chat-ui.agent')` (in the "dispatches the job with config(ai-chat-ui.agent)..." test) → `config('ai-kit.chat.agent')`, and its `it(...)` description string likewise. In `ToolApprovalTest.php` and `ChatConversationComponentTest.php`: `config(['ai-chat-ui.tool_views' => ...])` → `config(['ai-kit.chat.tool_views' => ...])` (two occurrences total, both in `ChatConversationComponentTest.php`).

`tests/Feature/ConversationRouteBindingTest.php`: the route path literal `/ai-chat-ui-test/{conversation}` is just a test-local URL, not a package identifier — rename to `/ai-kit-test/{conversation}` for consistency (both occurrences, plus the two `$this->get('/ai-chat-ui-test/...)` calls).

- [ ] **Step 9: Run the suite**

```bash
vendor/bin/pest
```

Expected: full suite green (this task is a pure rename — `config('ai-kit.chat.agent')` still resolves since the key is still present, just renamed/nested).

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Rename ai-chat-ui runtime identifiers to ai-kit

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01GCYzk1TaNJwyTRZfUW66Ha
EOF
)"
```

---

## Task 3: Required `agent` prop — remove the config-default fallback

Behavioral change: `conversation`'s `agent` prop stops falling back to a config default and becomes required. `config('ai-kit.chat.agent')` is removed.

**Files:**
- Modify: `resources/views/components/chat/conversation.blade.php`, `config/ai-kit.php`, `tests/Feature/ConfigTest.php`, `tests/Feature/ChatConversationComponentTest.php`, `tests/Feature/ToolApprovalTest.php`

**Interfaces:**
- Consumes: `Smwks\LaravelAiKit\Testbench\EchoAgent` (Task 1/2 FQCN) as the fixture agent every test now passes explicitly.
- Produces: `conversation`'s `agent` prop is `public string $agent` (required, no default) — any embed of this component without an `agent` prop now errors when Livewire dehydrates the component's public properties after mount, since the typed property is never assigned.

- [ ] **Step 1: Write/update the failing tests first**

In `tests/Feature/ChatConversationComponentTest.php`, delete this entire test (it asserts behavior we're removing):

```php
it('dispatches the job with config(ai-chat-ui.agent) when no agent prop is given', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    Livewire::test('ai-kit::components.chat.conversation', [
        'conversation' => $conversation,
        'initialMessage' => 'Hello there',
    ]);

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job) {
        return $job->agentClass === config('ai-kit.chat.agent');
    });
});
```

(Exact text may differ slightly depending on Task 2's rename of this test — match on the test description "dispatches the job with config(...agent) when no agent prop is given".)

Add a new test in the same file confirming the new required-prop behavior:

```php
it('requires an agent prop — omitting it throws when Livewire dehydrates the component', function () {
    [, $conversation] = makeConversationFixture();

    Bus::fake();

    expect(fn () => Livewire::test('ai-kit::components.chat.conversation', [
        'conversation' => $conversation,
    ]))->toThrow(\Error::class);
});
```

- [ ] **Step 2: Run the new test to confirm it fails**

```bash
vendor/bin/pest --filter='requires an agent prop'
```

Expected: FAIL — today `agent` still has a `?string $agent = null` default, so omitting it doesn't throw.

- [ ] **Step 3: Make `agent` required and drop the config fallback**

In `resources/views/components/chat/conversation.blade.php`:

Replace:
```php
    /**
     * Agent class to use for this conversation, e.g. App\Ai\Agents\SupportAgent::class.
     * Falls back to config('ai-chat-ui.agent') when not given — pass this explicitly
     * when a site embeds more than one bot, so each host page pins its own agent
     * rather than sharing the single globally-configured one.
     */
    public ?string $agent = null;
```
with:
```php
    /**
     * Agent class (or container binding key) to use for this conversation, e.g.
     * App\Ai\Agents\SupportAgent::class. Required — this package has no app-wide
     * default agent, so every embed of this component names its own.
     */
    public string $agent;
```

Replace:
```php
    protected function resolvedAgentClass(): string
    {
        return $this->agent ?? config('ai-chat-ui.agent');
    }
```
with:
```php
    protected function resolvedAgentClass(): string
    {
        return $this->agent;
    }
```

- [ ] **Step 4: Remove `chat.agent` from config**

In `config/ai-kit.php`, remove the `'agent' => EchoAgent::class,` line from the `chat` array, and remove the now-unused `use Smwks\LaravelAiKit\Testbench\EchoAgent;` import at the top of the file.

- [ ] **Step 5: Update `ConfigTest.php`**

Remove `expect(config('ai-kit.chat.agent'))->toBe(EchoAgent::class);` and the now-unused `use Smwks\LaravelAiKit\Testbench\EchoAgent;` import from `tests/Feature/ConfigTest.php`.

- [ ] **Step 6: Add `agent` to every remaining `conversation` component test invocation**

In `tests/Feature/ChatConversationComponentTest.php` and `tests/Feature/ToolApprovalTest.php`, every `Livewire::test('ai-kit::components.chat.conversation', [...])` call that doesn't already set `'agent'` needs one. Run, from the repo root:

```bash
perl -pi -e "s/'conversation' => (\\\$conversation\\w*),/'conversation' => \\1, 'agent' => EchoAgent::class,/g" tests/Feature/ChatConversationComponentTest.php tests/Feature/ToolApprovalTest.php
perl -pi -e "s/'conversation' => (\\\$conversation\\w*)\\]/'conversation' => \\1, 'agent' => EchoAgent::class]/g" tests/Feature/ChatConversationComponentTest.php tests/Feature/ToolApprovalTest.php
```

This is safe even for the one call in `ChatConversationComponentTest.php` that already passes `'agent' => EchoToolAgent::class` explicitly (the "dispatches the job with the given agent prop instead of the config default" test) — PHP array literals allow a duplicate key, and the later, explicit `'agent' => EchoToolAgent::class` wins over the inserted earlier `'agent' => EchoAgent::class`, so that test's intent is unchanged.

Add `use Smwks\LaravelAiKit\Testbench\EchoAgent;` to `tests/Feature/ToolApprovalTest.php`'s import list (it doesn't currently import `EchoAgent`).

Rename the test description "dispatches the job with the given agent prop instead of the config default" (in `ChatConversationComponentTest.php`) to "dispatches the job with the given agent prop" — there's no more config default to contrast it with.

- [ ] **Step 7: Run the full suite**

```bash
vendor/bin/pest
```

Expected: full suite green, including the new "requires an agent prop" test.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Make conversation's agent prop required; drop the config-default fallback

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01GCYzk1TaNJwyTRZfUW66Ha
EOF
)"
```

---

## Task 4: `InstallCommand` — unconditionally verify turns/events tables too

Behavioral addition: `ai-kit:install` now also checks the turns/events tables exist (not just `laravel/ai`'s own), since turns is always active.

**Files:**
- Modify: `src/Console/Commands/InstallCommand.php`, `tests/Feature/InstallCommandTest.php`

**Interfaces:**
- Consumes: `config('ai-kit.turns.tables.turns')` / `config('ai-kit.turns.tables.events')` (produced by Task 2).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/InstallCommandTest.php`:

```php
it('fails fast with instructions when this package\'s own turns/events tables are missing, even though laravel/ai\'s tables exist', function () {
    Schema::dropIfExists('agent_conversation_events');
    Schema::dropIfExists('agent_conversation_turns');

    $this->artisan('ai-kit:install')
        ->expectsOutputToContain('agent_conversation_turns')
        ->expectsOutputToContain('vendor:publish --tag=ai-kit-migrations')
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
vendor/bin/pest tests/Feature/InstallCommandTest.php --filter="own turns/events tables are missing"
```

Expected: FAIL — today's command reports success (exit 0) as long as `laravel/ai`'s own tables exist, regardless of the turns/events tables.

- [ ] **Step 3: Implement the check**

Replace the body of `src/Console/Commands/InstallCommand.php` with:

```php
<?php

namespace Smwks\LaravelAiKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InstallCommand extends Command
{
    protected $signature = 'ai-kit:install';

    protected $description = 'Verify laravel/ai\'s and this package\'s own conversation tables exist before using ai-kit';

    public function handle(): int
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $turnsTable = config('ai-kit.turns.tables.turns', 'agent_conversation_turns');
        $eventsTable = config('ai-kit.turns.tables.events', 'agent_conversation_events');

        if (! Schema::hasTable($conversationsTable) || ! Schema::hasTable($messagesTable)) {
            $this->error("Missing laravel/ai's own tables ({$conversationsTable} / {$messagesTable}). Run:");
            $this->line('  php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"');
            $this->line('  php artisan migrate');
            $this->line('then re-run this command.');

            return self::FAILURE;
        }

        if (! Schema::hasTable($turnsTable) || ! Schema::hasTable($eventsTable)) {
            $this->error("Missing ai-kit's own tables ({$turnsTable} / {$eventsTable}). Run:");
            $this->line('  php artisan vendor:publish --tag=ai-kit-migrations');
            $this->line('  php artisan migrate');
            $this->line('then re-run this command.');

            return self::FAILURE;
        }

        $this->info("Found {$conversationsTable}, {$messagesTable}, {$turnsTable}, and {$eventsTable} — ai-kit is ready to use.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the new test, then the whole file, to confirm they pass**

```bash
vendor/bin/pest tests/Feature/InstallCommandTest.php
```

Expected: all three tests in this file pass (the two pre-existing ones still pass since the turns/events tables exist by default in the test DB via migrations; the new one passes since dropping just the turns/events tables now trips the second check).

- [ ] **Step 5: Run the full suite**

```bash
vendor/bin/pest
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
ai-kit:install now also verifies the turns/events tables exist

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01GCYzk1TaNJwyTRZfUW66Ha
EOF
)"
```

---

## Task 5: Documentation rewrite

Updates `README.md`, `CLAUDE.md`, `AGENTS.md`, and `docs/agent-resolution.md` to describe the renamed package, the `Turns`/`Chat` split, the new config shape, and the required `agent` prop. No code changes in this task.

**Files:**
- Modify: `README.md`, `CLAUDE.md`, `AGENTS.md`, `docs/agent-resolution.md`

**Interfaces:**
- None — documentation only.

- [ ] **Step 1: Mechanical identifier pass across all four files**

```bash
perl -pi -e '
  s/smwks\/laravel-ai-chat-ui/smwks\/laravel-ai-kit/g;
  s/Smwks\\LaravelAiChatUi/Smwks\\LaravelAiKit/g;
  s/LaravelAiChatUiServiceProvider/LaravelAiKitServiceProvider/g;
  s/ai-chat-ui::/ai-kit::/g;
  s/data-ai-chat-ui/data-ai-kit/g;
  s/ai-chat-ui-conversation-started/ai-kit-conversation-started/g;
  s/ai-chat-ui-conversation-selected/ai-kit-conversation-selected/g;
  s/ai-chat-ui:install/ai-kit:install/g;
' README.md CLAUDE.md AGENTS.md docs/agent-resolution.md
```

- [ ] **Step 2: Fix publish tags and config-key references by hand (the mechanical pass above deliberately doesn't touch these — they don't map 1:1)**

In `README.md`: `--tag=ai-chat-ui-config` → `--tag=ai-kit-config`; `--tag=ai-chat-ui-migrations` → `--tag=ai-kit-migrations`; `--tag=ai-chat-ui-assets` → `--tag=ai-kit-chat-assets`; `--tag=ai-chat-ui-views` → `--tag=ai-kit-chat-views`. Same four substitutions in `AGENTS.md`/`CLAUDE.md`'s "Publish tags" line and "Conventions" section.

Every `config('ai-chat-ui.tool_views')` reference → `config('ai-kit.chat.tool_views')`; every `config('ai-chat-ui.tables.*')` reference in prose → `config('ai-kit.turns.tables.*')`; `config('ai-chat-ui.queue.*')` → `config('ai-kit.chat.queue.*')`. In `CLAUDE.md`/`AGENTS.md`'s "Trace capture" table, the four `Context` key rows (`ai-chat-ui.conversation_id` / `.turn_id`, `ai-chat-ui.tool_source`, `ai-chat-ui.tool_invocation_id`, `ai-chat-ui.pending_http_request`) → `ai-kit.*`.

In `README.md`'s "Trace correlation" table, same four `Context` key renames.

- [ ] **Step 3: Rewrite the title and opening pitch in `README.md`**

Replace:
```markdown
# Laravel AI Chat UI

*`smwks/laravel-ai-chat-ui`*
```
with:
```markdown
# Laravel AI Kit

*`smwks/laravel-ai-kit`*
```

Replace the opening paragraph and "Features" intro (the two paragraphs right after the badges, through the end of the `## Features` bullet list) with:

```markdown
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
```

- [ ] **Step 4: Rewrite "Installation" and drop the "Out of the box" default-agent paragraph**

Replace:
```markdown
Out of the box, the package uses `Smwks\LaravelAiChatUi\Testbench\EchoAgent` — a trivial
agent with no tools — so the install is runnable without any host-app agent code, as long
as `laravel/ai`'s own provider/API key is configured. See "Using your own agent" to swap
in your own.
```
(after Step 1's mechanical pass this already reads `Smwks\LaravelAiKit\Testbench\EchoAgent`)
with:
```markdown
There is no app-wide default agent — every embed of `chat.conversation` passes its own
`agent` prop. `Smwks\LaravelAiKit\Testbench\EchoAgent` — a trivial agent with no tools — is
bundled for exactly this: point `agent` at it to get the install running end-to-end before
writing your own agent, as long as `laravel/ai`'s own provider/API key is configured. See
"Using your own agent" to swap in your own.
```

- [ ] **Step 5: Rewrite the `agent` prop doc and "Using your own agent" section**

Replace:
```markdown
- `agent` (class name string) — use an agent other than `config('ai-chat-ui.agent')`
  for this conversation; see "Using your own agent" for running more than one bot.
```
(post-mechanical-pass: unaffected, still reads `config('ai-chat-ui.agent')` since this wasn't in the mechanical list — fix explicitly here)
with:
```markdown
- `agent` (string, **required**) — the agent class name (or container binding key) this
  conversation runs; see "Using your own agent". There is no app-wide default, so every
  embed of `chat.conversation` must pass one.
```

Replace the entire `## Using your own agent` section through the end of `### Running more than one bot` (i.e. from `## Using your own agent` up to, but not including, `### A `{conversation}` route segment and implicit binding`) with:

```markdown
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

```

- [ ] **Step 6: Fix the "Extension points" section's config/namespace references**

Replace:
```markdown
- Give a specific tool its own "show thoughts" detail view via `config('ai-chat-ui.tool_views')`,
```
with:
```markdown
- Give a specific tool its own "show thoughts" detail view via `config('ai-kit.chat.tool_views')`,
```

Replace the code fence:
```php
  // config/ai-chat-ui.php
  'tool_views' => [
      'weather' => 'chat.tools.weather-details',
  ],
```
with:
```php
  // config/ai-kit.php
  'chat' => [
      'tool_views' => [
          'weather' => 'chat.tools.weather-details',
      ],
  ],
```

- [ ] **Step 7: Update `CLAUDE.md`/`AGENTS.md`'s "What this is", "Architecture", and "Config" sections**

Replace the opening two sentences:
```markdown
`smwks/laravel-ai-chat-ui` — a Composer library (not an app) providing a Livewire chat UI and
"show thoughts" trace inspector for [`laravel/ai`](https://github.com/laravel/ai) agents.
PSR-4: `Smwks\LaravelAiChatUi\` → `src/`, `Smwks\LaravelAiChatUi\Tests\` → `tests/`.
Auto-discovered service provider: `LaravelAiChatUiServiceProvider`.
```
(post-mechanical-pass this already reads `smwks/laravel-ai-kit` / `Smwks\LaravelAiKit\` / `LaravelAiKitServiceProvider`)
with:
```markdown
`smwks/laravel-ai-kit` — a Composer library (not an app) providing a turn/event data model
plus a Livewire chat UI and "show thoughts" trace inspector for
[`laravel/ai`](https://github.com/laravel/ai) agents. PSR-4: `Smwks\LaravelAiKit\` → `src/`
(split into `Turns/` — models, event-capture listeners, `QueryTracker` — and `Chat/` — the
job, policy — plus top-level `Console/Commands/` and `Testbench/` shared by both),
`Smwks\LaravelAiKit\Tests\` → `tests/`. Auto-discovered service provider:
`LaravelAiKitServiceProvider`, which registers both modules unconditionally — there are no
feature flags; requiring the package activates turns, and additionally wiring your own
route to a chat component activates chat (the package owns no routes itself).
```

Add a one-line note right after the "Route model binding workaround" section, before "## Config":

```markdown
### Module boundary

`Turns` (`src/Turns/`) never depends on `Chat` (`src/Chat/`) — including
`Contracts\HasStatusMessage`, which lives under `Turns\Contracts` because it's consumed by
`Turns\Listeners\CaptureToolInvoking`, even though its only purpose is feeding the chat UI's
"thinking" status string.
```

Replace:
```markdown
## Config (`config/ai-chat-ui.php`)

- `tables` — names for the two owned tables.
- `agent` — app-wide default agent class (defaults to the bundled `Testbench\EchoAgent`). Per-conversation override: the `agent` prop on `conversation`. The package does **not** persist which agent a conversation used — routing must consistently pair a conversation with its agent.
- `tool_views` — map a tool name (from `tool.invoked` payload `['tool']`) to a Blade view for its detail panel; unlisted tools use the generic partial.
- `queue` — `connection` / `name` for `ProcessChatMessage`.
```
with:
```markdown
## Config (`config/ai-kit.php`)

- `turns.tables` — names for the two owned tables.
- `chat.tool_views` — map a tool name (from `tool.invoked` payload `['tool']`) to a Blade view for its detail panel; unlisted tools use the generic partial.
- `chat.queue` — `connection` / `name` for `ProcessChatMessage`.

There is no `chat.agent` config key — the `agent` prop on `conversation` is required, not
optional. The package does **not** persist which agent a conversation used — routing must
consistently pair a conversation with its agent.
```

- [ ] **Step 8: Update `docs/agent-resolution.md`'s stale references**

This document is an open, undecided future-design problem statement, not something this
restructure resolves — leave its content and candidate directions (A/B/C) as-is beyond the
mechanical rename from Step 1. Additionally fix these two config-path references by hand
(not covered by Step 1's mechanical list, since they need the new nesting):
`config('ai-chat-ui.agent')` (in "Summary" and "How agent resolution works today") → drop
entirely, replacing with a note that the config-default fallback no longer exists as of
this restructure (only the `agent` *prop* remains, and it's now required) — reword the
"Summary" paragraph's second sentence to: `the required `agent` prop on
`components.chat.conversation`. That string is resolved with `app($string)` in two
independent places...` (i.e. remove the `, or config('ai-chat-ui.agent') as the app-wide
default` clause). Do the same in "How agent resolution works today"'s bullet list —
replace the `config/ai-chat-ui.php` — `'agent' => EchoAgent::class` by default; a
per-conversation override is the `agent` prop.` bullet with: `` `agent` is a required prop
on `conversation` — there is no app-wide config default (removed as part of the
laravel-ai-kit restructure). ``

- [ ] **Step 9: Proofread and commit**

Skim all four files for any remaining `ai-chat-ui` or `LaravelAiChatUi` string:

```bash
grep -rn 'ai-chat-ui\|LaravelAiChatUi' README.md CLAUDE.md AGENTS.md docs/agent-resolution.md
```

Expected: no output. Fix anything found, then:

```bash
git add -A
git commit -m "$(cat <<'EOF'
Update README/CLAUDE.md/AGENTS.md for the laravel-ai-kit rename and restructure

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01GCYzk1TaNJwyTRZfUW66Ha
EOF
)"
```

---

## Task 6: Final verification

**Files:** none (verification only).

- [ ] **Step 1: Full repo-wide scan for stragglers**

```bash
grep -rIn 'ai-chat-ui\|AiChatUi\|LaravelAiChatUi\|laravel-ai-chat-ui' --include='*' . 2>/dev/null | grep -v '^\./vendor/' | grep -v '^\./\.git/' | grep -v 'docs/superpowers/'
```

Expected: no output (the `docs/superpowers/` exclusion is because this plan file and the design spec legitimately narrate the old name as history — that's fine). If anything else turns up, fix it.

- [ ] **Step 2: Composer validation and full suite**

```bash
composer validate --no-check-all
composer dump-autoload
vendor/bin/pest
```

Expected: `composer.json` valid, autoloading clean, full suite green.

- [ ] **Step 3: Confirm git is clean**

```bash
git status
```

Expected: working tree clean (everything committed across Tasks 1–5).
