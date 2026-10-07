<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprint_commitments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sprint');
            $table->string('item_id', 32);
            $table->boolean('committed');
            $table->timestamps();

            $table->unique(['sprint', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_commitments');
    }
};
