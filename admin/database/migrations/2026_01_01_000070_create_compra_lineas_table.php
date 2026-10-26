<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Línea de una compra. Al crearla suma su cantidad al stock del hogar (ver CompraLinea::booted). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('alimento_id')->constrained('alimentos')->restrictOnDelete();
            $table->decimal('cantidad', 10, 2);
            $table->string('unidad', 10);
            $table->date('caduca_el')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_lineas');
    }
};
