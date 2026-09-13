# Problem: resolving a per-conversation agent when every agent is one class

**Status:** Problem statement — no solution chosen yet.
**Date:** 2026-09-03
**Validated by:** `demo/` app (to be built) — see "How we'll validate" below.

## Summary

`laravel-ai-kit` identifies which agent runs a conversation by a **string** — the required
`agent` prop on `components.chat.conversation`. That string is resolved with `app($string)`
in two independent places: the web request that renders the component, and the queue
worker that runs `ProcessChatMessage`. In the common case the string is an agent **class
name**, and `app()` just constructs it.

This breaks for an app where every agent is the *same* class configured by a database
row (call it `DatabaseAgent`). A class name can no longer identify *which* configuration
to load, and the string is the only thing that crosses the queue boundary. Consumers
today work around it by registering one container binding per row, keyed by the row id.
That works but is a leaky, verbose pattern we'd like the library to absorb.

## How agent resolution works today

Relevant code:

- `resources/views/components/chat/conversation.blade.php` — `resolvedAgentClass()`:
  `return $this->agent;`
- `src/Chat/Jobs/ProcessChatMessage.php` — constructor stores that string as `$agentClass`;
  `handle()` does `$agent = app($this->agentClass);` then
  `$agent->continue($conversationId, as: ...)->prompt($message)`.
- `agent` is a required prop on `conversation` — there is no app-wide config default
  (removed as part of the laravel-ai-kit restructure).
- README: "Running more than one bot", "A `{conversation}` route segment and implicit
  binding", "Trace correlation".

Properties that constrain any change:

1. **The reference must survive queue serialization.** `ProcessChatMessage` is
   dispatched with the string and re-resolves it in a *different process* with no
   request or Livewire context. Whatever identifies the agent has to be a plain,
   self-describing value that both processes can independently turn back into the
   right agent.
2. **The agent must not be a singleton.** Trace correlation runs through the `Context`
   facade precisely because the agent binding isn't shared (see README "Trace
   correlation"). A fix must keep resolving a fresh agent per call.
3. **`app($string)` already accepts more than class names.** Any container abstract —
   including a binding key a service provider registered — is a valid argument. The
   current workaround leans entirely on this.
4. **Nothing persists which agent a conversation belongs to.** Neither owned table
   (`agent_conversation_turns`, `agent_conversation_events`) has an agent column. The
   library punts this to the consumer's routing: pair a conversation URL with the
   right agent consistently, or resuming picks the wrong one.

## The problem, concretely

Motivating app: a multi-bot admin where non-developers create and configure agents —
name, system prompt, model, enabled tools — as rows in an `agents` table. All rows are
served by one `DatabaseAgent` class that reads its behaviour from the row it was built
for.

- `DatabaseAgent::class` as the `agent` string is useless: it names the class, not the
  row. Every bot would resolve to an unconfigured `DatabaseAgent`.
- The component *has* the row at render time (it's on the page), but by the time
  `ProcessChatMessage` runs in the worker, all that's left is the string. So the string
  itself has to encode the row identity, and something registered in **both** processes
  has to know how to turn that string back into a configured `DatabaseAgent`.

## Current workaround

Give each row its own container binding, keyed by the row id, registered in a service
provider (loaded by web and worker alike):

```php
// AgentRecord::chatUiBinding() returns e.g. "ai-kit.agent.42"
foreach (AgentRecord::all() as $record) {
    $this->app->bind($record->chatUiBinding(), fn () => new DatabaseAgent($record));
}
```

Then pass `$record->chatUiBinding()` as the `agent` prop. `app($key)` in the request and
in the worker both hit the closure and build a fresh, correctly-configured agent.

A lazier variant avoids the boot-time `AgentRecord::all()` query by registering on
demand:

```php
$this->app->beforeResolving(function ($abstract) {
    if (str_starts_with($abstract, 'ai-kit.agent.') && ! $this->app->bound($abstract)) {
        $id = Str::afterLast($abstract, '.');
        $this->app->bind($abstract, fn () => new DatabaseAgent(AgentRecord::findOrFail($id)));
    }
});
```

### Why this is unsatisfying

- **Leaky abstraction.** The `agent` prop is documented as "agent class name". Making it
  work requires knowing it's really "any container abstract" and that the queue
  re-resolves it out of process.
- **Verbose.** N bindings, or a `beforeResolving` string-parsing hook, for what is
  conceptually "resolve agent id 42".
- **Consumer owns a constraint that's ours.** The double-resolution across the queue
  boundary is an internal detail of `ProcessChatMessage`; consumers shouldn't have to
  design around it.
- **Still no conversation→agent link.** Orthogonal to this, but the same area: resuming
  a conversation with the right agent depends entirely on consumer routing discipline.

## Constraints a solution must satisfy

- Reference is a plain serializable value; re-resolvable in the worker with no request
  or Livewire context.
- Agent stays non-singleton — fresh instance per resolution.
- Backward compatible: an `agent` string that is a class name (or an existing container
  key) keeps working with no consumer change.
- Minimal new API surface; no new required table.
- Doesn't assume the consumer uses Filament, routes, or any particular page structure.

## Candidate directions (not decided)

Ordered by increasing surface area. The `demo/` app is meant to tell us which is right.

### A. Documentation only

Rename the prop's documented meaning to "agent container key **or** class name" and ship
a README recipe for the DB-row case (both the eager and `beforeResolving` variants).
No code change. Cheapest; leaves all the plumbing with the consumer.

### B. A single overridable agent resolver

Add one hook — `config('ai-kit.chat.agent_resolver')` or
`LaravelAiKit::resolveAgentUsing(fn (string $ref) => Agent)`. The `agent` prop /
config value becomes an **opaque reference string** (a class name, `"42"`, `"db:42"`,
anything). `resolvedAgentClass()` becomes `resolvedAgent()`; `ProcessChatMessage` stores
the reference and calls the same resolver in `handle()`. Default resolver is
`fn ($ref) => app($ref)`, so every current usage is unchanged. Consumer registers **one**
closure instead of N bindings.

Optional extension: pass the `Conversation` (and/or participant) to the resolver, which
starts to address the conversation→agent link — probably out of scope for a first pass.

### C. Named-agent registry

`LaravelAiKit::agent('support', SupportAgent::class)` and
`LaravelAiKit::agent('db-42', fn () => new DatabaseAgent(...))`. The prop takes a
short name; the registry is consulted in both processes. Also tidies the plain
multi-class case (no FQCNs in Blade). Most API to design, document, and maintain.

## Open questions

- Is the opaque-reference resolver (B) enough, or do consumers also need the
  `Conversation` in hand to resolve correctly (i.e. should the library finally persist a
  conversation→agent reference)?
- If we persist a conversation→agent reference: new column on `agent_conversation_turns`,
  a new tiny table, or lean on `laravel/ai`'s conversation metadata if it has any?
- Does `resolveAgentUsing` belong on a facade/singleton, in config, or both (config for
  the static case, callback for the dynamic case)?
- Backward-compat shim: keep `resolvedAgentClass()` as a deprecated alias, or is the
  Blade component internal enough to rename outright?

## How we'll validate

Build `demo/` — a real Laravel app consuming this package the way a real consumer would
(path composer repository, its own `.env`, queue worker, migrations) — that reproduces
the motivating case:

- an `agents` table with a few rows (name, system prompt, model, tool set);
- a single `DatabaseAgent` class reading its config from the row it was built for;
- the chat UI starting and resuming conversations against a chosen row, with the
  **queue worker** resolving the same row's configuration out of process;
- "show thoughts" confirming the right instructions/model/tools were used.

First commit of `demo/` reproduces the problem with today's workaround, so the pain is
visible. Then we apply a candidate direction and measure it against: how much consumer
code it takes, whether it's obvious, and whether it survives the queue boundary without
the consumer thinking about it.
