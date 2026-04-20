<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.serials', 'inventorix_serials');
        $locationsTable = config('inventorix.tables.locations', 'inventorix_locations');
        $movementsTable = config('inventorix.tables.movements', 'inventorix_movements');

        Schema::create($tableName, function (Blueprint $table) use ($locationsTable, $movementsTable) {
            $table->id();
            $table->morphs('stockable');
            $table->foreignId('location_id')->constrained($locationsTable)->cascadeOnDelete();
            $table->string('serial_number')->unique();
            $table->string('status')->default('available');
            $table->unsignedBigInteger('reservation_id')->nullable()->index();
            $table->foreignId('movement_id')->nullable()->constrained($movementsTable)->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['stockable_type', 'stockable_id', 'location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.serials', 'inventorix_serials'));
    }
};
