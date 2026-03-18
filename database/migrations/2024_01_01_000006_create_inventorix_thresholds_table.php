<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.thresholds', 'inventorix_thresholds');
        $locationsTable = config('inventorix.tables.locations', 'inventorix_locations');

        Schema::create($tableName, function (Blueprint $table) use ($locationsTable) {
            $table->id();
            $table->morphs('stockable');
            $table->foreignId('location_id')->nullable()->constrained($locationsTable)->nullOnDelete();
            $table->decimal('min_quantity', 15, 4)->default(0);
            $table->decimal('max_quantity', 15, 4)->nullable();
            $table->timestamps();

            $table->unique(['stockable_type', 'stockable_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.thresholds', 'inventorix_thresholds'));
    }
};
