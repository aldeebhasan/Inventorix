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
}
