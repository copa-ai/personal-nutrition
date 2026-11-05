<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menú semanal que se le encarga al agente. Columnas de encargo (hogar y primer día), de EJECUCIÓN (estado, registro, tandas)
 * y de RESULTADO (`contenido` con la entrega validada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── encargo ──
            $table->string('nombre');
            $table->foreignUuid('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->date('fecha_inicio');                    // primer día del menú; el menú cubre 7 días desde aquí
            $table->json('encargo')->nullable();             // copia del encargo enviado al agente (con ella se valida la entrega)

            // ── resultado ──
            $table->json('contenido')->nullable();   // JSON de entrega del agente, incluida la clave `consumo` (pestaña Consumo)
            $table->string('huella', 64)->nullable(); // sha256 del contenido: detecta reimportaciones sin cambios

            // ── ejecucion ──
            $table->string('estado', 20)->default('borrador'); // borrador | en_cola | en_marcha | en_espera | terminado | fallido
            $table->string('archivo_log')->nullable();         // registro legible (stream-json traducido) en agente/workspace/
            $table->string('archivo_resultado')->nullable();
            $table->unsignedBigInteger('proceso_pid')->nullable();   // solo si claude corre en el proceso de la administracion
            $table->string('ejecucion_id', 64)->nullable();          // id de la ejecucion en el runner del agente (para cancelarla)
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('terminado_en')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('intervalo_ciclos_min')->nullable(); // vacio = config('agente.ciclos.intervalo_min')
            $table->unsignedInteger('ciclo')->default(0);               // tandas lanzadas hasta ahora
            $table->timestamp('proximo_ciclo_en')->nullable();          // cuando toca la siguiente (estado en_espera)
            $table->boolean('detener_pedido')->default(false);          // se pidio parar con una tanda en marcha: al acabar no hay otra

            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'proximo_ciclo_en']); // planificador de tandas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
