<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monday_connections', function (Blueprint $table) {
            $table->id();
            $table->string('board_id')->default('');
            $table->text('token')->nullable();
            $table->json('column_map')->nullable();
            $table->unsignedSmallInteger('refresh_minutes')->default(5);
            $table->json('capacity_overrides')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_connections');
    }
};
