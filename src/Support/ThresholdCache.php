<?php

namespace Aldeebhasan\Inventorix\Support;

use Aldeebhasan\Inventorix\Models\Threshold;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class ThresholdCache
{
    private CacheRepository $cache;

    public function __construct()
    {
        $store = config('inventorix.threshold_cache.store');
        $this->cache = $store ? Cache::store($store) : Cache::store();
    }

    public function get(string $stockableType, int|string $stockableId, int|string|null $locationId): ?Threshold
    {
        if (! config('inventorix.threshold_cache.enabled', true)) {
            return $this->fetchFromDb($stockableType, $stockableId, $locationId);
        }

        $ttl = (int) config('inventorix.threshold_cache.ttl', 300);

        return $this->cache->remember(
            $this->key($stockableType, $stockableId, $locationId),
            $ttl,
            fn () => $this->fetchFromDb($stockableType, $stockableId, $locationId)
        );
    }

    public function forget(string $stockableType, int|string $stockableId, int|string|null $locationId): void
    {
        if (! config('inventorix.threshold_cache.enabled', true)) {
            return;
        }


        // Also forget the global (null location) key when a location-specific one is cleared.
        if ($locationId === null) {
            $this->cache->forget($this->key($stockableType, $stockableId, null));
        }else{
            $this->cache->forget($this->key($stockableType, $stockableId, $locationId));
        }
    }

    private function key(string $stockableType, int|string $stockableId, int|string|null $locationId): string
    {
        $loc = $locationId ?? 'global';
        $type = str_replace('\\', '_', $stockableType);

        return "inventorix:threshold:{$type}:{$stockableId}:{$loc}";
    }

    private function fetchFromDb(string $stockableType, int|string $stockableId, int|string|null $locationId): ?Threshold
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
