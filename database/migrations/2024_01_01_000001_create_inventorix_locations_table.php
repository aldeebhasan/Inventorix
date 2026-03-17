<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('inventorix.tables.locations', 'inventorix_locations');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on(config('inventorix.tables.locations', 'inventorix_locations'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventorix.tables.locations', 'inventorix_locations'));
    }
};
