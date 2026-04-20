<?php

namespace Aldeebhasan\Inventorix\Enums;

enum SerialStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
}
