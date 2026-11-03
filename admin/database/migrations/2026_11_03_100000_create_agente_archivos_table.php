<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los archivos que se intercambian con el agente (encargos/, workspace/, base/), en la base de datos compartida en vez de en
     * un bucket local de cada dispositivo. El contenido va en base64: asi es texto en cualquier motor y no hay que tratar los bytea de
     * PDO (que devuelve un stream).
     */
    public function up(): void
    {
        Schema::create('agente_archivos', function (Blueprint $table) {
            $table->id();
            $table->string('ruta')->unique();          // relativa a agente/: «encargos/encargo-3.json», «workspace/resultado-3.json»...
            $table->longText('contenido');             // base64
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agente_archivos');
    }
};
