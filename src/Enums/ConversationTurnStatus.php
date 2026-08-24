<?php

namespace Smwks\LaravelAiChatUi\Enums;

enum ConversationTurnStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Complete = 'COMPLETE';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Complete => 'Complete',
            self::Failed => 'Failed',
        };
    }
}
