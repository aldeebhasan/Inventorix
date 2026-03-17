<?php

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
        'thresholds' => 'inventorix_thresholds',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Bindings
    |--------------------------------------------------------------------------
    | Swap any internal model with your own subclass.
    */
    'models' => [
        'stock' => \Aldeebhasan\Inventorix\Models\Stock::class,
        'movement' => \Aldeebhasan\Inventorix\Models\Movement::class,
        'location' => \Aldeebhasan\Inventorix\Models\Location::class,
        'reservation' => \Aldeebhasan\Inventorix\Models\Reservation::class,
        'transaction' => \Aldeebhasan\Inventorix\Models\Transaction::class,
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

];
