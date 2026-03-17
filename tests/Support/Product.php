<?php

namespace Aldeebhasan\Inventorix\Tests\Support;

use Aldeebhasan\Inventorix\Traits\HasInventory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasInventory;

    protected $fillable = ['name', 'cost_price'];
}
