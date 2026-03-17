<?php

namespace Aldeebhasan\Inventorix\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $message = 'Insufficient stock available for this operation.')
    {
        parent::__construct($message);
    }
}
