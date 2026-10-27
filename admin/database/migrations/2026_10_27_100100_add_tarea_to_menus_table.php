<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un mismo menú sirve para tres tareas del agente: generar, editar con un comentario o intercambiar una toma. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('tarea', 20)->default('generar')->after('hogar_id'); // generar | editar | intercambiar
            $table->text('instruccion')->nullable()->after('tarea');            // comentario de la edición o lo que se comió
            $table->text('instruccion_pendiente')->nullable()->after('instruccion'); // intercambios pedidos mientras corría otro (se lanzan al acabar)
            $table->json('contenido_anterior')->nullable()->after('contenido'); // contenido previo a la última edición (para deshacer)
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['tarea', 'instruccion', 'instruccion_pendiente', 'contenido_anterior']);
        });
    }
};
