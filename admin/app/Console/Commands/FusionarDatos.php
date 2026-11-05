<?php

namespace App\Console\Commands;

use App\Services\Fusion\Fusionador;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Fusiona en esta base el fichero de otra (`nutrition:exportar-datos`). Todo o nada; lo descartado queda en la auditoria. */
#[Signature('nutrition:fusionar-datos {entrada : Fichero NDJSON de nutrition:exportar-datos}')]
#[Description('Fusiona en esta base los datos de otra (de un fichero de nutrition:exportar-datos)')]
class FusionarDatos extends Command
{
    public function handle(): int
    {
        $flujo = is_file((string) $this->argument('entrada')) ? fopen((string) $this->argument('entrada'), 'r') : false;
        if ($flujo === false) {
            $this->error('No se puede leer el fichero.');

            return self::FAILURE;
        }
        try {
            $resumen = (new Fusionador)->fusionar(DB::connection()->getPdo(), $flujo);
        } catch (Throwable $e) {
            $this->error('No se fusiono nada: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            fclose($flujo);
        }

        foreach ($resumen as $concepto => $n) {
            $this->line(str_pad((string) $concepto, 26).$n);
        }

        return self::SUCCESS;
    }
}
