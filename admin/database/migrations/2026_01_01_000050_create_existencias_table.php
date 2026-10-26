<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock actual del hogar: lo que hay ahora en casa. Se suma al registrar una compra y se corrige a mano. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('alimento_id')->constrained('alimentos')->restrictOnDelete();
            $table->decimal('cantidad', 10, 2)->default(0);
            $table->string('unidad', 10);
            $table->date('caduca_el')->nullable();

            $table->index(['hogar_id', 'alimento_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('existencias');
    }
};
