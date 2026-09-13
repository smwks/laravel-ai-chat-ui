<?php

namespace Smwks\LaravelAiKit\Turns\Contracts;

interface HasStatusMessage
{
    /**
     * Describe what this invocation is doing, for display in the chat UI's
     * "thinking" indicator while the tool is running.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function statusMessage(array $arguments): string;
}
