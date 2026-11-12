<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'chat';

    /**
     * Estado por instancia de la lista de la compra (interfaz «cards»): el mazo actual (la lista de ese momento, tomada cuando la persona pide el mazo), las decisiones
     * de cada tarjeta, los eventos versionados de Watch y las ilustraciones de los alimentos. Vive en el SQLite del disco de la instancia.
     */
    public function up(): void
    {
        Schema::connection('chat')->create('cards_mazos', function (Blueprint $table): void {
            $table->string('deck_id', 64)->primary();
            $table->string('menu_id', 64)->nullable();
            $table->string('titulo', 256);
            $table->json('tarjetas');
            $table->unsignedBigInteger('creado_ms');
            $table->unsignedBigInteger('actualizado_ms');
            $table->timestamps();
        });

        // La version de cada evento es su id autoincremental: crece siempre y no se reutiliza, ni tras reiniciar.
        Schema::connection('chat')->create('cards_eventos', function (Blueprint $table): void {
            $table->id();
            $table->string('deck_id', 64);
            $table->string('tipo', 16);
            $table->json('carga');
            $table->unsignedBigInteger('creado_ms');
        });

        Schema::connection('chat')->create('cards_decisiones', function (Blueprint $table): void {
            $table->id();
            $table->string('deck_id', 64)->index();
            $table->string('item_id', 64);
            $table->string('action_id', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->boolean('undo')->default(false);
            $table->boolean('deshecha')->default(false);
            $table->unsignedBigInteger('acted_ms')->nullable();
            $table->json('efecto')->nullable();      // lo que cambio en existencias, para poder deshacerlo exactamente
            $table->unsignedBigInteger('creado_ms');

            $table->index(['deck_id', 'item_id']);
        });

        Schema::connection('chat')->create('cards_ilustraciones', function (Blueprint $table): void {
            $table->string('clave', 191)->primary();  // nombre del alimento normalizado
            $table->string('nombre', 191);
            $table->string('hash', 64)->nullable();   // SHA3-256 del PNG en chat_archivos (cache local del SVG de alimentos.ilustracion); null mientras no hay
            $table->string('svg_sha', 40)->nullable(); // SHA-1 del SVG de alimentos.ilustracion del que sale ese PNG: si cambia, se vuelve a dibujar
            $table->string('estado', 16)->default('pendiente');   // pendiente | ok | fallida (los intentos y esperas del encargo al agente, por instancia)
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->unsignedBigInteger('ultimo_intento_ms')->nullable();
            $table->timestamps();
        });

        // Encargos al agente (para el tope de coste diario).
        Schema::connection('chat')->create('cards_lotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('creado_ms')->index();
            $table->unsignedSmallInteger('alimentos');
        });
    }

    public function down(): void
    {
        Schema::connection('chat')->dropIfExists('cards_lotes');
        Schema::connection('chat')->dropIfExists('cards_ilustraciones');
        Schema::connection('chat')->dropIfExists('cards_decisiones');
        Schema::connection('chat')->dropIfExists('cards_eventos');
        Schema::connection('chat')->dropIfExists('cards_mazos');
    }
};
