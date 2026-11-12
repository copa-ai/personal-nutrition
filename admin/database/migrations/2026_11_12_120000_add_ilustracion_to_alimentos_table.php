<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ilustración de cada alimento para las tarjetas de la lista de la compra: el SVG que dibuja el agente (ya comprobado con
 * App\Services\Compra\SaneadorSvg), compartido por todas las instancias que usan la base. Queda fuera del núcleo del esquema declarado
 * (database/esquema-nucleo.json), así que no cambia la red. La auditoría solo anota su tamaño.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table): void {
            $table->text('ilustracion')->nullable();
        });
        DB::table('auditoria_tablas')->where('tabla', 'alimentos')->update(['omitir' => 'ilustracion']);
    }

    public function down(): void
    {
        DB::table('auditoria_tablas')->where('tabla', 'alimentos')->update(['omitir' => '']);
        Schema::table('alimentos', function (Blueprint $table): void {
            $table->dropColumn('ilustracion');
        });
    }
};
