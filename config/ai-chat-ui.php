<?php

use Smwks\LaravelAiChatUi\Testbench\EchoAgent;

return [

    'tables' => [
        'turns' => 'ai_chat_ui_turns',
        'events' => 'ai_chat_ui_events',
    ],

    'agent' => EchoAgent::class,

    'queue' => [
        'connection' => null,
        'name' => 'default',
    ],

];
