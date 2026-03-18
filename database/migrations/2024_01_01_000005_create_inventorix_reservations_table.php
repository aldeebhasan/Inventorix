<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.reservations', 'inventorix_reservations');
        $locationsTable = config('inventorix.tables.locations', 'inventorix_locations');

        Schema::create($tableName, function (Blueprint $table) use ($locationsTable) {
            $table->id();
            $table->morphs('stockable');
            $table->foreignId('location_id')->constrained($locationsTable)->cascadeOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->string('status')->default('pending');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['stockable_type', 'stockable_id', 'location_id']);
            $table->index(['status', 'expires_at']);
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.reservations', 'inventorix_reservations'));
    }
};
