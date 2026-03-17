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
            $table->string('stockable_type');
            $table->unsignedBigInteger('stockable_id');
            $table->foreignId('location_id')->constrained($locationsTable)->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('status')->default('pending');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.reservations', 'inventorix_reservations'));
    }
};
