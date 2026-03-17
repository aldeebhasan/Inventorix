<?php

namespace Aldeebhasan\Inventorix\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Released = 'released';
    case Fulfilled = 'fulfilled';
}
