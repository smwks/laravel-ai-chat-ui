<?php

namespace Smwks\LaravelAiKit\Testbench;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class HttpCallingTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'A tool that makes its own outbound HTTP call, used only in package tests.';
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) Http::get('https://example.com/tool-fetch')->body();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
