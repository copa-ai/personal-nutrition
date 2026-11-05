<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Interruptores de la instancia (chat habilitado, acceso automatico...): clave unica y valor json (jsonb en PostgreSQL). */
    public function up(): void
    {
        $json = DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json';

        Schema::create('ajustes', function (Blueprint $table) use ($json): void {
            $table->id();
            $table->string('clave')->unique();
            $table->{$json}('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes');
    }
};
