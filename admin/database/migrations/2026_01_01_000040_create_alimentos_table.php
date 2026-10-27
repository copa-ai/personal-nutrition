<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo de alimentos que se guardan en casa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alimentos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('categoria', 20)->default('otro');   // CategoriaComida
            $table->string('unidad', 10)->default('g');         // unidad habitual: g, kg, ml, l, ud
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alimentos');
    }
};
