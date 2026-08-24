<?php

namespace Smwks\LaravelAiChatUi\Listeners;

use Laravel\Ai\Events\InvokingTool;
use Smwks\LaravelAiChatUi\Services\QueryTracker;

class CaptureToolInvoking
{
    public function __construct(protected QueryTracker $tracker) {}

    public function __invoke(InvokingTool $event): void
    {
        $this->tracker->startTracking();
    }
}
