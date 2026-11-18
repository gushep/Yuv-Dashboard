<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Par de chaves que assina os tokens do OAuth (Passport). Fica no banco, com a chave privada criptografada pela
     * APP_KEY, para não depender de um segredo novo no Kubernetes. A linha mais recente é a chave em uso.
     */
    public function up(): void
    {
        Schema::create('passport_keys', function (Blueprint $table) {
            $table->id();
            $table->text('private_key');
            $table->text('public_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_keys');
    }
};
