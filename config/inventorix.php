<?php

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;

// config for Aldeebhasan/Inventorix
return [

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    | Override the default database table names used by Inventorix.
    */
    'tables' => [
        'stocks' => 'inventorix_stocks',
        'movements' => 'inventorix_movements',
        'locations' => 'inventorix_locations',
        'reservations' => 'inventorix_reservations',
        'transactions' => 'inventorix_transactions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Bindings
    |--------------------------------------------------------------------------
    | Swap any internal model with your own subclass.
    */
    'models' => [
        'stock' => Stock::class,
        'movement' => Movement::class,
        'location' => Location::class,
        'reservation' => Reservation::class,
        'transaction' => Transaction::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Location
    |--------------------------------------------------------------------------
    | The ID of the default location used when no location is specified.
    | Set to null to require an explicit location on every operation.
    */
    'default_location_id' => env('INVENTORIX_DEFAULT_LOCATION', null),

    /*
    |--------------------------------------------------------------------------
    | Allow Negative Stock
    |--------------------------------------------------------------------------
    | When true, deducting more than available stock is allowed globally.
    | Can be overridden per-call via the 'allow_negative' option.
    */
    'allow_negative_stock' => env('INVENTORIX_ALLOW_NEGATIVE', false),

    /*
    |--------------------------------------------------------------------------
    | Reservation Expiry
    |--------------------------------------------------------------------------
    | Default TTL (in minutes) for reservations. null = never expire.
    */
    'reservation_ttl_minutes' => env('INVENTORIX_RESERVATION_TTL', null),

    /*
    |--------------------------------------------------------------------------
    | Movement Pruning
    |--------------------------------------------------------------------------
    | Movements older than this many days will be pruned by the
    | `inventorix:prune-movements` command. null = never prune.
    */
    'movement_prune_after_days' => env('INVENTORIX_PRUNE_DAYS', null),

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    | Set 'enabled' to false to disable all events.
    | List specific event class short-names in 'disable' to skip selectively.
    */
    'events' => [
        'enabled' => true,
        'disable' => [], // e.g. ['StockAdded', 'StockDeducted']
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Alerts
    |--------------------------------------------------------------------------
    | When true, threshold alert events are dispatched on a queue.
    */
    'queue_alerts' => env('INVENTORIX_QUEUE_ALERTS', false),
    'alert_queue' => env('INVENTORIX_ALERT_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Threshold Caching
    |--------------------------------------------------------------------------
    | When enabled, threshold records are cached to avoid a DB query on every
    | stock write operation. Thresholds are invalidated automatically when
    | updated via setStockThreshold().
    */
    'threshold_cache' => [
        'enabled' => env('INVENTORIX_THRESHOLD_CACHE', true),
        'ttl' => env('INVENTORIX_THRESHOLD_TTL', 300), // seconds
        'store' => env('INVENTORIX_THRESHOLD_CACHE_STORE', null), // null = default store
    ],

    /*
    |--------------------------------------------------------------------------
    | Costing Strategy
    |--------------------------------------------------------------------------
    | The costing method used by totalValuation() to determine the value of
    | on-hand inventory. Supported values:
    |
    |   'fifo'    — First In First Out (default): oldest stock sold first;
    |               on-hand inventory is valued at the cost of newest batches.
    |   'lifo'    — Last In First Out: newest stock sold first;
    |               on-hand inventory is valued at the cost of oldest batches.
    |   'average' — Weighted Average: on-hand stock is valued at the
    |               weighted average cost across all inbound movements.
    |
    | The strategy is only applied when movements carry a cost_per_unit value.
    | When no cost data is recorded on movements, valuation falls back to the
    | stockable model's cost attribute (cost_price by default).
    */
    'costing_strategy' => env('INVENTORIX_COSTING', 'fifo'),

];
