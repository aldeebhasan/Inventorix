<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.movement_sources', 'inventorix_movement_sources');
        $movementsTable = config('inventorix.tables.movements', 'inventorix_movements');

        Schema::create($tableName, function (Blueprint $table) use ($movementsTable) {
            $table->id();
            $table->foreignId('deduction_movement_id')->constrained($movementsTable)->restrictOnDelete();
            $table->foreignId('source_movement_id')->constrained($movementsTable)->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->timestamps();

            $table->unique(['deduction_movement_id', 'source_movement_id']);
            $table->index('source_movement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.movement_sources', 'inventorix_movement_sources'));
    }
};
