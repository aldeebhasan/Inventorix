<?php

namespace Aldeebhasan\Inventorix\Enums;

enum MovementType: string
{
    case Add = 'add';
    case Deduct = 'deduct';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Adjustment = 'adjustment';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case Fulfillment = 'fulfillment';
}
