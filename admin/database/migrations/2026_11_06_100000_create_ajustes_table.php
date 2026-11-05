<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Interruptores de la instancia (chat habilitado, acceso automatico...): clave unica y valor jsonb. */
    public function up(): void
    {
        Schema::create('ajustes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('clave')->unique();
            $table->jsonb('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes');
    }
};
