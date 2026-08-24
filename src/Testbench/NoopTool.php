<?php

namespace Smwks\LaravelAiChatUi\Testbench;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class NoopTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'A no-op tool used only in package tests.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'noop-result';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
