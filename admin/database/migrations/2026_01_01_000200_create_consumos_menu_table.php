<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de consumo de cada menú: una fila por respuesta del modelo (message.id de Claude Code), con los tokens, el modelo
 * que respondio y el coste calculado en el momento de registrarla con las tarifas vigentes ENTONCES (`precios` guarda la
 * tarifa usada). Cambiar config/agente.php despues no altera lo ya registrado. Es un libro de gasto: no se borra al reimportar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumos_menu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->string('mensaje_id');
            $table->unsignedInteger('ciclo')->nullable();        // tanda del menú en la que se registro
            $table->string('fase')->nullable();
            $table->string('agente')->default('principal');       // principal | subagente:<id>
            $table->string('modelo')->nullable();                 // el que declara el registro de Claude Code
            $table->timestamp('fecha')->nullable();               // cuando respondio el modelo
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read')->default(0);
            $table->unsignedBigInteger('cache_creation')->default(0);
            $table->unsignedBigInteger('thinking_tokens')->default(0);   // ya incluidos en output_tokens
            $table->unsignedInteger('web_search')->default(0);
            $table->unsignedInteger('web_fetch')->default(0);
            // Tarifa y coste congelados (USD). Sin tarifa para el modelo: precios y costes a null.
            $table->json('precios')->nullable();                  // {modelo_tarifa, entrada, salida, cache_lectura, cache_escritura, busqueda_web, fecha}
            $table->decimal('coste_entrada', 14, 8)->nullable();
            $table->decimal('coste_salida', 14, 8)->nullable();
            $table->decimal('coste_cache', 14, 8)->nullable();
            $table->decimal('coste_web', 14, 8)->nullable();
            $table->decimal('coste_total', 14, 8)->nullable();
            $table->timestamps();

            $table->unique(['menu_id', 'mensaje_id']);
            $table->index(['menu_id', 'fase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_menu');
    }
};
