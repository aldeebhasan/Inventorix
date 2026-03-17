<?php

namespace Aldeebhasan\Inventorix\Exceptions;

use RuntimeException;

class InvalidQuantityException extends RuntimeException
{
    public function __construct(string $message = 'Quantity must be greater than zero.')
    {
        parent::__construct($message);
    }
}
