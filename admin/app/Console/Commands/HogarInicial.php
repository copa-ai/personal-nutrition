<?php

namespace App\Console\Commands;

use App\Models\Hogar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Crea el hogar de la aplicación si todavía no hay ninguno. El servicio lo ejecuta en cada arranque, tras migrar. */
#[Signature('hogar:inicial')]
#[Description('Crea el hogar único de la aplicación si no existe')]
class HogarInicial extends Command
{
    public function handle(): int
    {
        $existia = Hogar::query()->exists();
        $hogar = Hogar::unico();
        $this->info($existia ? "Ya hay hogar: «{$hogar->nombre}»." : "Creado el hogar «{$hogar->nombre}».");

        return self::SUCCESS;
    }
}
