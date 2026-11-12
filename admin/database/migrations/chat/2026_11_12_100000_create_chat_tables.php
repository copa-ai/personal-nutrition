<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'chat';

    /**
     * El chat (interfaz «chat» de chatui): una sola conversacion principal por instancia, sus mensajes, las fotos y los
     * pedidos de secretos. Viven en un SQLite del disco de la instancia (conexion `chat`), no en PostgreSQL: cada instancia
     * tiene su propia conversacion. Se ejecuta con `migrate --database=chat --path=database/migrations/chat`.
     */
    public function up(): void
    {
        // Una sola fila (id 1): sirve para bloquear y repartir `sequence` sin huecos ni duplicados.
        Schema::connection('chat')->create('chat_conversacion', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('titulo', 256)->nullable();
            $table->unsignedBigInteger('titulo_ms')->nullable();
            $table->unsignedBigInteger('creada_ms');
            $table->unsignedBigInteger('ultima_secuencia')->default(0);
            $table->timestamps();
        });

        Schema::connection('chat')->create('chat_mensajes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('message_id', 64)->unique();
            $table->unsignedBigInteger('sequence')->unique();
            $table->json('contenido');
            $table->string('estado', 16);
            $table->text('razonamiento')->default('');
            $table->text('error')->nullable();
            $table->string('reply_to', 64)->nullable()->index();
            $table->boolean('pinned')->default(false);
            $table->json('metadata')->nullable();
            $table->boolean('from_service')->default(false);
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->json('sugerencias')->nullable();
            $table->json('corrector')->nullable();
            $table->unsignedBigInteger('created_ms');
            $table->unsignedBigInteger('updated_ms');
            $table->timestamps();

            $table->index(['from_service', 'estado']);
        });

        Schema::connection('chat')->create('chat_archivos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('hash', 64)->unique();      // SHA3-256 en hexadecimal minusculas
            $table->string('mime', 127);
            $table->unsignedBigInteger('tamano');
            $table->string('nombre')->nullable();
            $table->binary('contenido');
            $table->timestamps();
        });

        // Nunca se guarda el valor del secreto, solo que se pidio y como se resolvio.
        Schema::connection('chat')->create('chat_peticiones_secreto', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('request_id', 64)->unique();
            $table->string('message_id', 64)->index();
            $table->string('nombre', 128);
            $table->string('estado', 16)->default('pendiente');   // pendiente | respondida | omitida
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('chat')->dropIfExists('chat_peticiones_secreto');
        Schema::connection('chat')->dropIfExists('chat_archivos');
        Schema::connection('chat')->dropIfExists('chat_mensajes');
        Schema::connection('chat')->dropIfExists('chat_conversacion');
    }
};
