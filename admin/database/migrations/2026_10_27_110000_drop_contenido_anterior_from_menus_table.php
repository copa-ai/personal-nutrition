<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Deshacer edición» se sustituye por el calendario de lo comido y lo planificado: ya no se guarda copia del menú anterior. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('contenido_anterior');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->json('contenido_anterior')->nullable()->after('contenido');
        });
    }
};
