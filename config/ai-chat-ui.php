<?php

use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

return [

    'tables' => [
        'turns' => 'agent_conversation_turns',
        'events' => 'agent_conversation_events',
    ],

    'agent' => EchoAgent::class,

    /*
    |--------------------------------------------------------------------------
    | Tool detail views
    |--------------------------------------------------------------------------
    |
    | Map a tool name (the value found in a "tool.invoked" event's
    | payload['tool']) to a Blade view name to render its "show thoughts"
    | detail panel with. Any tool not listed here falls back to the
    | package's own generic tool partial.
    |
    | 'weather' => 'chat.tools.weather-details',
    |
    */

    'tool_views' => [],

    'queue' => [
        'connection' => null,
        'name' => 'default',
    ],

];
