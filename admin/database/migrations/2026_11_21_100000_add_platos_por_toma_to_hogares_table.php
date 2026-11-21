<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platos de cada opción de comida y cena del menú de este hogar (p. ej. un primero y un segundo). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hogares', function (Blueprint $table) {
            $table->unsignedSmallInteger('platos_por_toma')->default(2)->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('hogares', function (Blueprint $table) {
            $table->dropColumn('platos_por_toma');
        });
    }
};
