<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Volatile state of the Coder funding (samples, balances, MU sent). This table has no audit trigger.
     * The `ajustes` table has one, and a sample every 5 minutes would fill the audit log for ever.
     */
    public function up(): void
    {
        Schema::create('coder_estado', function (Blueprint $table): void {
            $table->string('clave')->primary();
            $table->jsonb('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coder_estado');
    }
};
