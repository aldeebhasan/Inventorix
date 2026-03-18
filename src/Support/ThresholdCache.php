<?php

namespace Aldeebhasan\Inventorix\Support;

use Aldeebhasan\Inventorix\Models\Threshold;
use Illuminate\Support\Facades\Cache;

class ThresholdCache
{
    public static function get(string $stockableType, int|string $stockableId, int|string|null $locationId): ?Threshold
    {
        if (! config('inventorix.threshold_cache.enabled', true)) {
            return static::fetchFromDb($stockableType, $stockableId, $locationId);
        }

        $key = static::key($stockableType, $stockableId, $locationId);
        $ttl = (int) config('inventorix.threshold_cache.ttl', 300);
        $store = config('inventorix.threshold_cache.store');

        $cache = $store ? Cache::store($store) : Cache::store();

        return $cache->remember($key, $ttl, fn () => static::fetchFromDb($stockableType, $stockableId, $locationId));
    }

    public static function forget(string $stockableType, int|string $stockableId, int|string|null $locationId): void
    {
        if (! config('inventorix.threshold_cache.enabled', true)) {
            return;
        }

        $store = config('inventorix.threshold_cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();
        $cache->forget(static::key($stockableType, $stockableId, $locationId));

        // Also forget the global (null location) key when a location-specific one is cleared
        if ($locationId !== null) {
            $cache->forget(static::key($stockableType, $stockableId, null));
        }
    }

    private static function key(string $stockableType, int|string $stockableId, int|string|null $locationId): string
    {
        $loc = $locationId ?? 'global';
        $type = str_replace('\\', '_', $stockableType);

        return "inventorix:threshold:{$type}:{$stockableId}:{$loc}";
    }

    private static function fetchFromDb(string $stockableType, int|string $stockableId, int|string|null $locationId): ?Threshold
    {
        return Threshold::where('stockable_type', $stockableType)
            ->where('stockable_id', $stockableId)
            ->where(function ($q) use ($locationId) {
                if ($locationId !== null) {
                    $q->where('location_id', $locationId)->orWhereNull('location_id');
                } else {
                    $q->whereNull('location_id');
                }
            })
            ->orderByRaw('location_id IS NULL')
            ->first();
    }
}
