<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Qué opción elige el usuario en cada toma del menú. Alimenta el PDF y su lista de la compra. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            // {"2026-11-02/comida": 1, ...}: índice (desde 0) de la opción elegida. Sin entrada = la primera.
            $table->json('selecciones')->nullable()->after('contenido');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('selecciones');
        });
    }
};
