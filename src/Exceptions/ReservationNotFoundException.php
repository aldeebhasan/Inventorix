<?php

namespace Aldeebhasan\Inventorix\Exceptions;

use RuntimeException;

class ReservationNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'The specified reservation could not be found.')
    {
        parent::__construct($message);
    }
}
