<?php

namespace Aldeebhasan\Inventorix\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Location extends Model
{
    protected $fillable = [
        'name',
        'code',
        'parent_id',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.locations', 'inventorix_locations');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function descendantIds(): array
    {
        $table = $this->getTable();

        try {
            $sql = "WITH RECURSIVE descendants AS (
                SELECT id FROM {$table} WHERE parent_id = ?
                UNION ALL
                SELECT l.id FROM {$table} l
                INNER JOIN descendants d ON l.parent_id = d.id
            )
            SELECT id FROM descendants";

            $rows = DB::select($sql, [$this->id]);

            return array_column($rows, 'id');
        } catch (\Exception $e) {
            // Fallback: iterative BFS for databases without recursive CTE support
            $ids = [];
            $queue = [$this->id];

            while (! empty($queue)) {
                $parentIds = $queue;
                $children = static::whereIn('parent_id', $parentIds)->pluck('id')->toArray();
                $ids = array_merge($ids, $children);
                $queue = $children;
            }

            return $ids;
        }
    }
}
