<?php

namespace Aldeebhasan\Inventorix\Exceptions;

use RuntimeException;

class LocationNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'The specified location could not be found.')
    {
        parent::__construct($message);
    }
}
