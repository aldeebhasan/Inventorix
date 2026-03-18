<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.movements', 'inventorix_movements');
        $locationsTable = config('inventorix.tables.locations', 'inventorix_locations');
        $transactionsTable = config('inventorix.tables.transactions', 'inventorix_transactions');

        Schema::create($tableName, function (Blueprint $table) use ($locationsTable, $transactionsTable) {
            $table->id();
            $table->morphs('stockable');
            $table->foreignId('location_id')->constrained($locationsTable)->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained($transactionsTable)->nullOnDelete();
            $table->string('type');
            $table->decimal('quantity', 15, 4);
            $table->decimal('before_quantity', 15, 4);
            $table->decimal('after_quantity', 15, 4);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index('location_id');
            $table->index('transaction_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.movements', 'inventorix_movements'));
    }
};
