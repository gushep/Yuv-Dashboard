<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('item_id', 32)->unique();
            $table->text('summary');
            $table->string('source_hash', 64);
            $table->string('generated_by', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_summaries');
    }
};
