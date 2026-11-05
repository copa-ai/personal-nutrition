<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Persona del hogar. Lleva su modelo nutricional, sus restricciones y su feedback; el menú se adapta a cada una. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->string('nombre');
            $table->boolean('activo')->default(true);            // un perfil inactivo no entra en los menús nuevos

            // ── modelo nutricional del perfil ──
            $table->text('objetivo')->nullable();                // texto libre: «bajar de peso, alto en proteína, pocos procesados»
            $table->unsignedSmallInteger('kcal_diarias')->nullable();
            $table->unsignedTinyInteger('proteina_pct')->nullable(); // reparto de macros en % de las kcal (opcional)
            $table->unsignedTinyInteger('carbohidratos_pct')->nullable();
            $table->unsignedTinyInteger('grasa_pct')->nullable();
            $table->json('restricciones')->nullable();           // lista de textos: «sin lácteos», «sin gluten», «azúcar controlado»
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfiles');
    }
};
