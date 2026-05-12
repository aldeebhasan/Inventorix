<?php

namespace Aldeebhasan\Inventorix\Enums;

enum TransactionType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';
    case Manual = 'manual';
    case Reversal = 'reversal';

    // Types where a committed transaction for the same causable means "already done - skip".
    // Adjustment and Manual are excluded because they are legitimately repeatable.
    public static function idempotentTypes(): array
    {
        return [
            self::Purchase,
            self::Sale,
            self::Transfer,
            self::Reversal,
        ];
    }
}
