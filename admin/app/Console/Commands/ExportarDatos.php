<?php

namespace App\Console\Commands;

use App\Services\Fusion\Exportador;
use App\Services\Instancia;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Vuelca los datos de esta base a un fichero que otra base puede fusionar (`nutrition:fusionar-datos`). */
#[Signature('nutrition:exportar-datos {salida : Fichero NDJSON que se crea}')]
#[Description('Vuelca los datos de esta base a un fichero para fusionarlos en otra')]
class ExportarDatos extends Command
{
    public function handle(): int
    {
        $flujo = @fopen((string) $this->argument('salida'), 'w');
        if ($flujo === false) {
            $this->error('No se puede crear el fichero.');

            return self::FAILURE;
        }
        try {
            $cuenta = (new Exportador)->exportar(DB::connection()->getPdo(), $flujo, (string) Instancia::id());
        } finally {
            fclose($flujo);
        }

        foreach ($cuenta as $nombre => $filas) {
            $this->line(str_pad($nombre, 26).$filas);
        }

        return self::SUCCESS;
    }
}
