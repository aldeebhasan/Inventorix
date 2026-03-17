<?php

namespace Aldeebhasan\Inventorix\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
    case RolledBack = 'rolled_back';
}
