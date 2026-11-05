<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Menú activo del hogar (el que se sigue ahora) y los cambios por intercambio que lleva encima. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hogares', function (Blueprint $table) {
            $table->foreignUuid('menu_id')->nullable()->after('nombre')->constrained('menus')->nullOnDelete();
            // {"2026-11-02/comida": {"categorias": [...], "opciones": [...]}}: tomas que sustituyen a las del menú activo.
            $table->json('cambios')->nullable()->after('menu_id');
        });
    }

    public function down(): void
    {
        Schema::table('hogares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_id');
            $table->dropColumn('cambios');
        });
    }
};
