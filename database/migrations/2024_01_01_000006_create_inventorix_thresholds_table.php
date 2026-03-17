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
            $table->string('stockable_type');
            $table->unsignedBigInteger('stockable_id');
            $table->foreignId('location_id')->nullable()->constrained($locationsTable)->nullOnDelete();
            $table->integer('min_quantity')->default(0);
            $table->integer('max_quantity')->nullable();
            $table->timestamps();

            $table->unique(['stockable_type', 'stockable_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.thresholds', 'inventorix_thresholds'));
    }
};
