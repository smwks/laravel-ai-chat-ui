<?php

namespace Smwks\LaravelAiChatUi\Testbench;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Smwks\LaravelAiChatUi\Contracts\HasStatusMessage;
use Stringable;

class StatusMessageTool implements HasStatusMessage, Tool
{
    public function description(): Stringable|string
    {
        return 'A tool that reports a custom status message, used only in package tests.';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function statusMessage(array $arguments): string
    {
        return 'Looking up '.($arguments['value'] ?? 'something').'…';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'status-result';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
