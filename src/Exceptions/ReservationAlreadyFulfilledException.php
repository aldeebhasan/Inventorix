<?php

namespace Aldeebhasan\Inventorix\Exceptions;

use RuntimeException;

class ReservationAlreadyFulfilledException extends RuntimeException
{
    public function __construct(string $message = 'This reservation has already been fulfilled or released.')
    {
        parent::__construct($message);
    }
}
