<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tiempo del hogar para cocinar cada toma de cada día de la semana. Todas las recetas del menú tienen que caber aquí. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidad_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana');                 // DiaSemana (ISO: 1 = lunes … 7 = domingo)
            $table->unsignedSmallInteger('minutos_comida')->nullable(); // tiempo para cocinar «al momento»; null = ese día no se cocina
            $table->unsignedSmallInteger('minutos_cena')->nullable();
            $table->unsignedSmallInteger('minutos_desayuno')->nullable();  // tomas individuales: null = sin dato (no se limita; el agente propone algo sencillo o preparado el día antes)
            $table->unsignedSmallInteger('minutos_almuerzo')->nullable();
            $table->unsignedSmallInteger('minutos_merienda')->nullable();

            $table->unique(['hogar_id', 'dia_semana']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_dias');
    }
};
