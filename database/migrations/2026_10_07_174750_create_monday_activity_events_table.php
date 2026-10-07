<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monday_activity_events', function (Blueprint $table) {
            $table->id();
            $table->string('monday_id', 64);
            $table->string('board_id', 32);
            $table->string('item_id', 32)->default('');
            $table->string('event', 80);
            $table->string('column_id', 80)->nullable();
            $table->string('column_title')->nullable();
            $table->json('previous_value')->nullable();
            $table->json('value')->nullable();
            $table->string('user_id', 32)->nullable();
            $table->json('data');
            $table->dateTime('occurred_at', 6);
            $table->timestamps();

            $table->unique(['monday_id', 'item_id']);
            $table->index(['board_id', 'occurred_at']);
            $table->index(['item_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_activity_events');
    }
};
