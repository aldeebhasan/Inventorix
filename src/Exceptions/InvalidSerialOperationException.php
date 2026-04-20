<?php

namespace Aldeebhasan\Inventorix\Exceptions;

use RuntimeException;

class InvalidSerialOperationException extends RuntimeException
{
    public function __construct(string $message = 'Invalid serial number operation.')
    {
        parent::__construct($message);
    }
}
