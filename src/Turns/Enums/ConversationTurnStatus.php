<?php

namespace Smwks\LaravelAiKit\Turns\Enums;

enum ConversationTurnStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case AwaitingApproval = 'AWAITING_APPROVAL';
    case Complete = 'COMPLETE';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::AwaitingApproval => 'Awaiting approval',
            self::Complete => 'Complete',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the turn has reached a state the UI should stop polling a spinner for.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Complete, self::Failed, self::AwaitingApproval => true,
            self::Pending, self::Processing => false,
        };
    }
}
