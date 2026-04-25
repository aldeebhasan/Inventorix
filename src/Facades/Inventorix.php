<?php

namespace Aldeebhasan\Inventorix\Facades;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Aldeebhasan\Inventorix\Inventorix
 *
 * @method static Stock addStock(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options = null)
 * @method static Stock deductStock(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options = null)
 * @method static bool transfer(Model $stockable, int|float $quantity, Location|int $from, Location|int $to, ?StockOperationDto $options = null)
 * @method static Stock adjustStock(Model $stockable, int|float $newQuantity, Location|int $location, ?StockOperationDto $options = null)
 * @method static Transaction bulk(callable $callback, StockOperationDto $options = null)
 * @method static Reservation reserve(Model $stockable, int|float $quantity, Location|int $location, ?StockOperationDto $options = null)
 * @method static bool releaseReservation(Reservation|int $reservation)
 * @method static Stock fulfillReservation(Reservation|int $reservation)
 * @method static Builder movementsFor(Model $stockable, array $filters = [])
 * @method static Collection lowStockItems(Location|int|null $location = null, ?string $stockableType = null)
 * @method static float totalValuation(Location|int|null $location = null, ?Model $stockable = null, string $costAttribute = 'cost_price')
 * @method static void checkThresholds(Model $stockable, Location|int|null $location = null)
 * @method static Transaction rollback(Transaction $transaction, StockOperationDto $options = null)
 * @method static Builder movementsByCausable(Model $causable, array $filters = [])
 * @method static float valuationByCausable(Model $causable)
 * @method static float stockVelocity(Model $stockable, Location|int $location, int $days = 30)
 * @method static float daysOfStock(Model $stockable, Location|int $location, int $velocityDays = 30)
 * @method static Carbon|null peakDemandDay(Model $stockable, Location|int $location, int $days = 90)
 */
class Inventorix extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aldeebhasan\Inventorix\Inventorix::class;
    }
}
