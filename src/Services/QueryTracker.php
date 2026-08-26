<?php

namespace Smwks\LaravelAiChatUi\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

class QueryTracker
{
    protected bool $tracking = false;

    protected array $queries = [];

    protected static bool $listenerRegistered = false;

    protected static ?self $listenerInstance = null;

    public function __construct()
    {
        $this->registerListener();
    }

    protected function registerListener(): void
    {
        // Register listener if not yet registered, OR if the registered instance
        // is different from this one (indicating the container was reset)
        if (self::$listenerRegistered && self::$listenerInstance === $this) {
            return;
        }

        Event::listen(QueryExecuted::class, fn (QueryExecuted $event) => $this->recordQuery($event));

        self::$listenerRegistered = true;
        self::$listenerInstance = $this;
    }

    protected function recordQuery(QueryExecuted $event): void
    {
        // The listener is registered on the singleton instance that first called registerListener().
        // This is why QueryTracker must be a singleton — a second instance would not receive
        // events, since its registerListener() call would see self::$listenerRegistered already true
        // and skip registering its own closure.
        if ($this->tracking && ! $this->isOwnLoggingQuery($event->sql)) {
            $this->queries[] = [
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'time' => $event->time,
                'connection' => $event->connectionName,
            ];
        }
    }

    /**
     * A tool's own SQL trace shouldn't include this package's own writes to its
     * event/turn logging tables — those are an implementation detail of capturing
     * the trace, not something the tool itself did.
     */
    protected function isOwnLoggingQuery(string $sql): bool
    {
        foreach ([config('ai-chat-ui.tables.events'), config('ai-chat-ui.tables.turns')] as $table) {
            if ($table && str_contains($sql, $table)) {
                return true;
            }
        }

        return false;
    }

    public function startTracking(): void
    {
        $this->queries = [];
        $this->tracking = true;
    }

    /**
     * @return array<int, array{sql: string, bindings: array, time: float, connection: string}>
     */
    public function stopTracking(): array
    {
        $this->tracking = false;
        $queries = $this->queries;
        $this->queries = [];

        return $queries;
    }
}
