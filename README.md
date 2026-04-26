# Inventorix — Modern Inventory Control for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aldeebhasan/inventorix.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/inventorix)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/inventorix/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aldeebhasan/inventorix/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/inventorix/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aldeebhasan/inventorix/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/dfa531de17724ac787b23634fc652051)](https://app.codacy.com/gh/aldeebhasan/Inventorix/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Total Downloads](https://img.shields.io/packagist/dt/aldeebhasan/inventorix.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/inventorix)

Inventorix is a Laravel package that adds full inventory control to any Eloquent model. It handles stock tracking, movement history, reservations, FIFO/LIFO/Average costing, threshold alerts, serial number tracking, transaction rollback, and demand velocity — all without changing your existing models.

## Installation

```bash
composer require aldeebhasan/inventorix
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="inventorix-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="inventorix-config"
```

## Setup

Add the `HasInventory` trait to any Eloquent model you want to track:

```php
use Aldeebhasan\Inventorix\Traits\HasInventory;

class Product extends Model
{
    use HasInventory;
}
```

You must have at least one `Location` record in the database before performing stock operations. Locations represent warehouses, bins, or any physical storage unit and support parent/child hierarchies via `parent_id`.

## Basic Usage

You can use the `HasInventory` trait methods directly on the model, or the `Inventorix` facade for lower-level control.

### Stock Operations

```php
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;

// Add stock
$product->addStock(quantity: 100, location: $location);

// Deduct stock
$product->deductStock(quantity: 10, location: $location);

// Set stock to an absolute quantity (reconciliation)
$product->adjustStock(newQuantity: 50, location: $location);

// Transfer between locations
$product->transfer(quantity: 20, from: $warehouseA, to: $warehouseB);
```

### StockOperationDto

Pass a `StockOperationDto` as the last argument to any operation to control its behaviour:

```php
$options = new StockOperationDto(
    transaction: $existingTransaction,   // attach to an open bulk transaction
    causable: $order,                    // the model that caused this operation
    cost: 9.99,                          // explicit cost per unit (null = no cost, false = use model's cost_price)
    note: 'Purchase order #123',
    createdBy: auth()->id(),
    allowNegative: true,                 // allow stock to go below zero for this call
    expiresAt: now()->addHours(2),       // reservation TTL
    serials: ['SN-001', 'SN-002'],       // explicit serial numbers
    lotReference: 'LOT-2024-01',
    externalReference: 'PO-9876',
    reasonCode: 'purchase',
);

$product->addStock(100, $location, $options);
```

### Bulk / Grouped Transactions

Group multiple operations into a single atomic transaction:

```php
use Aldeebhasan\Inventorix\Facades\Inventorix;

$transaction = Inventorix::bulk(function ($transaction) use ($product, $location) {
    $options = new StockOperationDto(transaction: $transaction);

    $product->addStock(50, $location, $options);
    $anotherProduct->deductStock(5, $location, $options);
});
```

If any operation inside the callback throws, the transaction is marked `RolledBack` and the exception propagates.

### Transaction Rollback

Reverse a committed transaction by creating a compensating reversal:

```php
$reversalTransaction = Inventorix::rollback($transaction);
```

This replays every movement in reverse (adds become deducts and vice-versa), handles serial number compensation automatically, and fires a `TransactionRolledBack` event.

## Reservations

Reservations hold stock aside without permanently deducting it:

```php
// Reserve stock
$reservation = $product->reserve(quantity: 5, location: $location);

// Release the reservation (stock returns to available)
$product->releaseReservation($reservation);

// Fulfill the reservation (converts reserved stock to a real deduction)
$product->fulfillReservation($reservation);
```

Reservations can have a TTL set via config (`reservation_ttl_minutes`) or per-call via `StockOperationDto::$expiresAt`. Run the scheduled command to expire stale reservations:

```bash
php artisan inventorix:expire-reservations
```

## Querying Stock

```php
// Stock record at a specific location
$stock = $product->stockAt($location);

// Totals (optionally scoped to a location, with or without child locations)
$product->totalStock();
$product->totalStock($location, includeChildren: true);
$product->availableStock($location);   // total - reserved
$product->reservedStock($location);

// Is stock below the configured low-stock threshold?
$product->isLowStock($location);

// Full summary array
$product->stockSummary($location);
// Returns: total_quantity, reserved_quantity, available_quantity, locations[], is_low_stock, last_movement_at
```

## Valuation

```php
// Value of on-hand stock for this product (uses configured costing strategy)
$product->stockValuation($location);

// Total valuation across all stockables or scoped to a location
Inventorix::totalValuation($location);

// Valuation of movements caused by a specific model
Inventorix::valuationByCausable($order);
```

Costing strategy is set in config (`fifo`, `lifo`, or `average`). Movements must carry a `cost_per_unit` value (set via `StockOperationDto::$cost`) for movement-based costing to apply.

## Demand Velocity

```php
// Average units deducted per day over the last N days
$product->stockVelocity($location, days: 30);

// How many days until stock runs out at current velocity
$product->daysOfStock($location, velocityDays: 30);

// The calendar day with the highest deductions in the last N days
$product->peakDemandDay($location, days: 90);
```

## Thresholds & Alerts

```php
// Set a low-stock threshold for a product at a location
$product->setStockThreshold(location: $location, minQuantity: 10, maxQuantity: 500);

// Manually trigger threshold evaluation
$product->checkThresholds($location);
```

Threshold checks run automatically after every `addStock`, `deductStock`, and `adjustStock` call. When stock crosses a boundary the package fires `LowStockReached` or `OverstockReached`. To find all items currently below threshold:

```php
Inventorix::lowStockItems($location);          // scoped to a location
Inventorix::lowStockItems(stockableType: Product::class); // all products
```

Alert events can optionally be dispatched on a queue (`queue_alerts` / `alert_queue` in config). Threshold records are cached in-memory (configurable TTL via `threshold_cache`) to avoid a DB hit on every stock write.

## Serial Number Tracking

Enable in config:

```php
// config/inventorix.php
'serial_tracking' => [
    'enabled' => true,
],
```

When enabled, every `addStock` auto-generates a ULID serial number per unit, and every `deductStock` auto-consumes the oldest available serials at that location (FIFO). You can also supply explicit serial numbers:

```php
$product->addStock(2, $location, new StockOperationDto(serials: ['SN-A1', 'SN-A2']));
$product->deductStock(1, $location, new StockOperationDto(serials: ['SN-A1']));
```

Reservations also lock specific serials:

```php
$reservation = $product->reserve(1, $location, new StockOperationDto(serials: ['SN-A2']));
```

## Lifecycle Hooks

Register callbacks that fire before/after add and deduct operations:

```php
use Aldeebhasan\Inventorix\Facades\Inventorix;

Inventorix::beforeAdd(function ($stockable, $quantity, $location, $dto) {
    // called before every addStock
});

Inventorix::afterAdd(function ($stock, $movement) {
    // called after every addStock
});

Inventorix::beforeDeduct(function ($stockable, $quantity, $location, $dto) { });
Inventorix::afterDeduct(function ($stock, $movement) { });
```

## Custom Costing Strategy Per Model

Override the costing strategy for a specific model by implementing `inventorixCostingStrategy()`:

```php
use Aldeebhasan\Inventorix\Enums\CostingStrategy;

class Product extends Model
{
    use HasInventory;

    public function inventorixCostingStrategy(): CostingStrategy
    {
        return CostingStrategy::Average;
    }
}
```

## Events

All events live in `Aldeebhasan\Inventorix\Events\`. Disable all events or specific ones in config:

```php
'events' => [
    'enabled' => true,
    'disable' => ['StockAdded', 'StockDeducted'],
],
```

| Event | Fired when |
|---|---|
| `StockAdded` | Stock is added |
| `StockDeducted` | Stock is deducted |
| `StockAdjusted` | Stock is adjusted |
| `StockTransferred` | A transfer completes |
| `StockReserved` | A reservation is created |
| `ReservationReleased` | A reservation is released |
| `ReservationFulfilled` | A reservation is fulfilled |
| `ReservationExpired` | A reservation is expired by the command |
| `LowStockReached` | Stock falls at or below a min threshold |
| `OverstockReached` | Stock rises at or above a max threshold |
| `TransactionRolledBack` | A transaction is reversed |

## Artisan Commands

| Command | Description |
|---|---|
| `inventorix:expire-reservations` | Release all reservations past their TTL |
| `inventorix:prune-movements` | Delete movements older than `movement_prune_after_days` |
| `inventorix:stock-report` | Generate a stock report |

Schedule the expiry command in your application's scheduler:

```php
// routes/console.php (Laravel 11+)
Schedule::command('inventorix:expire-reservations')->hourly();
Schedule::command('inventorix:prune-movements')->daily();
```

## Configuration Reference

```php
// config/inventorix.php
return [
    'default_location_id'       => env('INVENTORIX_DEFAULT_LOCATION', null),
    'allow_negative_stock'       => env('INVENTORIX_ALLOW_NEGATIVE', false),
    'reservation_ttl_minutes'    => env('INVENTORIX_RESERVATION_TTL', null),
    'movement_prune_after_days'  => env('INVENTORIX_PRUNE_DAYS', null),
    'costing_strategy'           => env('INVENTORIX_COSTING', 'fifo'), // fifo | lifo | average
    'queue_alerts'               => env('INVENTORIX_QUEUE_ALERTS', false),
    'alert_queue'                => env('INVENTORIX_ALERT_QUEUE', 'default'),
    'events' => [
        'enabled' => true,
        'disable' => [], // short class names, e.g. ['StockAdded']
    ],
    'threshold_cache' => [
        'enabled' => env('INVENTORIX_THRESHOLD_CACHE', true),
        'ttl'     => env('INVENTORIX_THRESHOLD_TTL', 300),
        'store'   => env('INVENTORIX_THRESHOLD_CACHE_STORE', null),
    ],
    'serial_tracking' => [
        'enabled' => env('INVENTORIX_SERIAL_TRACKING', false),
    ],
    // All table names and model classes are swappable via 'tables' and 'models' keys.
];
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hasan Deeb](https://github.com/aldeebhasan)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
