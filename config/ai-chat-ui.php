<?php

use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

return [

    'tables' => [
        'turns' => 'ai_chat_ui_turns',
        'events' => 'ai_chat_ui_events',
    ],

    'agent' => EchoAgent::class,

    // The package registers no routes of its own — see the README's "Routing"
    // section for the routes a consuming app should define. These names are
    // what the package's own Livewire components use for their internal
    // cross-links (e.g. chat.new's "History" link) and redirect targets, so
    // either name your routes to match these defaults or override them here
    // to match whatever names you actually used.
    'routes' => [
        'names' => [
            'new' => 'chat.new',
            'history' => 'chat.history',
            'conversation' => 'chat.conversation',
        ],
    ],

    'queue' => [
        'connection' => null,
        'name' => 'default',
    ],

];
