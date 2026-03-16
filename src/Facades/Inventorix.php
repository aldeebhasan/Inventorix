<?php

namespace Aldeebhasan\Inventorix\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Aldeebhasan\Inventorix\Inventorix
 */
class Inventorix extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aldeebhasan\Inventorix\Inventorix::class;
    }
}
