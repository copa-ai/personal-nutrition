<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dónde vive el hogar, para que las frutas y verduras del menú sean de temporada: hemisferio (norte o sur) y zona (país o región). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hogares', function (Blueprint $table) {
            $table->string('hemisferio', 5)->default('norte')->after('platos_por_toma');
            $table->string('zona')->default('España')->after('hemisferio');
        });
    }

    public function down(): void
    {
        Schema::table('hogares', function (Blueprint $table) {
            $table->dropColumn(['hemisferio', 'zona']);
        });
    }
};
