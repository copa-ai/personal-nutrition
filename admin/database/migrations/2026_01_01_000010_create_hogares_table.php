<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hogar: agrupa a los perfiles y es dueño de lo compartido (stock, tiempos de cocinado y menús). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hogares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hogares');
    }
};
