<?php

namespace Aldeebhasan\Inventorix\Facades;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Aldeebhasan\Inventorix\Inventorix
 *
 * @method static Stock addStock(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options)
 * @method static Stock deductStock(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options)
 * @method static bool transfer(Model $stockable, int|float $quantity, Location|int $from, Location|int $to, StockOperationDto $options)
 * @method static Stock adjustStock(Model $stockable, int|float $newQuantity, Location|int $location, StockOperationDto $options)
 * @method static Transaction bulk(callable $callback, StockOperationDto $options)
 * @method static Reservation reserve(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options)
 * @method static bool releaseReservation(Reservation|int $reservation)
 * @method static Stock fulfillReservation(Reservation|int $reservation)
 * @method static Builder movementsFor(Model $stockable, array $filters = [])
 * @method static Collection lowStockItems(Location|int|null $location = null, ?string $stockableType = null)
 * @method static float totalValuation(Location|int|null $location = null, string $costAttribute = 'cost_price')
 * @method static void checkThresholds(Model $stockable, Location|int|null $location = null)
 */
class Inventorix extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aldeebhasan\Inventorix\Inventorix::class;
    }
}
