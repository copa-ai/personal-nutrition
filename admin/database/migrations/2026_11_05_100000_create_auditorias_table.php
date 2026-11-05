<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de auditoria: una fila por movimiento. Sin clave foranea hacia users para que sobreviva al borrado del usuario
     * (user_nombre y user_email guardan una instantanea). Las columnas json son jsonb en PostgreSQL.
     */
    public function up(): void
    {
        $json = DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json';

        Schema::create('auditorias', function (Blueprint $table) use ($json): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre')->nullable();
            $table->string('user_email')->nullable();
            $table->string('evento')->index();
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->{$json}('valores_antiguos')->nullable();
            $table->{$json}('valores_nuevos')->nullable();
            $table->text('descripcion')->nullable();
            $table->{$json}('datos')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('url')->nullable();
            $table->string('metodo', 16)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
