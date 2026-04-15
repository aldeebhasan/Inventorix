<?php

namespace Aldeebhasan\Inventorix\Enums;

enum MovementType: string
{
    case Add = 'add';
    case Deduct = 'deduct';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case Fulfillment = 'fulfillment';
}
