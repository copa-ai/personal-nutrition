<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lo que un perfil comió DE VERDAD. Es el historial que recibe el agente: lo planificado en un menú no cuenta como comido. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_comida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('perfiles')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('momento', 10);                       // MomentoComida
            $table->string('origen', 10);                        // OrigenComida: menu | casa | fuera
            $table->text('descripcion');                         // lo que se comió de verdad
            $table->json('categorias')->nullable();              // lista de CategoriaComida
            $table->foreignId('menu_id')->nullable()->constrained('menus')->nullOnDelete(); // menú del que salió (origen = menu)
            $table->string('opcion_ref')->nullable();            // qué opción del menú: «2026-11-02/comida/1»

            $table->index(['perfil_id', 'fecha']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_comida');
    }
};
