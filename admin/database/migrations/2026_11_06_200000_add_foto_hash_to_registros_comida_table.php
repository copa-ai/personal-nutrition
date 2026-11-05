<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** La foto de lo que se comió: hash SHA3-256 (hex) de un archivo de chat_archivos. Sin clave foránea dura: la foto se puede purgar sin tocar el registro. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registros_comida', function (Blueprint $table) {
            $table->string('foto_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('registros_comida', function (Blueprint $table) {
            $table->dropIndex(['foto_hash']);
            $table->dropColumn('foto_hash');
        });
    }
};
