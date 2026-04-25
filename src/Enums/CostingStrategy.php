<?php

namespace Aldeebhasan\Inventorix\Enums;

enum CostingStrategy: string
{
    case Fifo = 'fifo';
    case Lifo = 'lifo';
    case Average = 'average';
    case Fefo = 'fefo';

    public static function fromConfig(): self
    {
        return self::tryFrom(config('inventorix.costing_strategy', 'fifo')) ?? self::Fifo;
    }
}
