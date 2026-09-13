<?php

namespace Smwks\LaravelAiKit\Testbench;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct()
    {
        $this->requireApproval('Confirm before running the approval tool.');
    }

    public function description(): Stringable|string
    {
        return 'A tool that always requires approval; used only in package tests.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'approval-tool-result';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
