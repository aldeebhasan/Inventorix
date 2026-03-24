<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.transactions', 'inventorix_transactions');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->nullableMorphs('causable');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.transactions', 'inventorix_transactions'));
    }
};
