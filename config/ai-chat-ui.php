<?php

use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

return [

    'tables' => [
        'turns' => 'ai_chat_ui_turns',
        'events' => 'ai_chat_ui_events',
    ],

    'agent' => EchoAgent::class,

    'routes' => [
        'enabled' => true,
        'prefix' => 'chat',
        'middleware' => ['web', 'auth'],
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
