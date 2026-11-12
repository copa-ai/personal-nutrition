<?php

namespace App\Console\Commands;

use App\Services\EsquemaBaseDatos as Esquema;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Construye el esquema de la base de datos migrada como claves `db.table.*` del `formal` de la red Celaut (gramatica en
 * App\Services\EsquemaBaseDatos) y lo escribe en database/esquema.json o lo compara con el que hay. Solo declara el NUCLEO que lista
 * database/esquema-nucleo.json (tablas y columnas que no van a cambiar), no toda la base: anadir columnas o tablas fuera del nucleo no
 * cambia el `formal` ni, por tanto, la red.
 *
 * Los tipos son los de PostgreSQL: hay que correrlo con la conexion pgsql ya migrada. Tras cada migracion nueva:
 *   php artisan migrate && php artisan nutrition:esquema --escribir && python3 database/sincronizar-esquema.py
 */
#[Signature('nutrition:esquema {--escribir : Reescribe database/esquema.json} {--comprobar : Falla si database/esquema.json difiere de la base migrada} {--forzar : Permite un motor que no sea pgsql (los tipos seran distintos)}')]
#[Description('Genera o comprueba el esquema de la base de datos que va en el formal de la red')]
class EsquemaBaseDatos extends Command
{
    public function handle(): int
    {
        if ($this->option('escribir') && $this->option('comprobar')) {
            $this->error('--escribir y --comprobar no se pueden usar juntos.');

            return self::FAILURE;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql' && ! $this->option('forzar')) {
            $this->error("La conexion usa {$driver}: los tipos serian distintos a los de PostgreSQL y el formal no valdria. Usa DB_CONNECTION=pgsql o --forzar.");

            return self::FAILURE;
        }

        try {
            $esquema = $this->leer();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ruta = database_path('esquema.json');

        if ($this->option('escribir')) {
            file_put_contents($ruta, json_encode($esquema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->info('Escrito '.$ruta.' ('.count($esquema).' tablas). Falta copiarlo a los service.json: python3 database/sincronizar-esquema.py');

            return self::SUCCESS;
        }

        if ($this->option('comprobar')) {
            return $this->comprobar($esquema, $ruta);
        }

        foreach ($esquema as $clave => $valor) {
            $this->line("{$clave}={$valor}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function leer(): array
    {
        $actual = Schema::getCurrentSchemaName();
        $tablas = [];
        foreach (Schema::getTables() as $tabla) {
            if (isset($tabla['schema']) && $tabla['schema'] !== $actual) {
                continue;
            }
            $nombre = $tabla['name'];
            $tablas[$nombre] = [
                'columns' => Schema::getColumns($nombre),
                'indexes' => Schema::getIndexes($nombre),
                'foreign_keys' => Schema::getForeignKeys($nombre),
            ];
        }

        $ruta = database_path('esquema-nucleo.json');
        $nucleo = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (! is_array($nucleo) || $nucleo === []) {
            throw new InvalidArgumentException("{$ruta} no existe, esta vacio o no es un JSON valido (tabla => lista de columnas).");
        }

        return Esquema::mapa($tablas, $nucleo);
    }

    /** @param  array<string, string>  $esquema */
    private function comprobar(array $esquema, string $ruta): int
    {
        $escrito = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (! is_array($escrito)) {
            $this->error("{$ruta} no existe o no es un JSON valido.");

            return self::FAILURE;
        }

        $difiere = false;
        foreach (array_unique([...array_keys($escrito), ...array_keys($esquema)]) as $clave) {
            if (! array_key_exists($clave, $esquema)) {
                $this->error("{$clave}: esta en esquema.json y la base no tiene esa tabla.");
            } elseif (! array_key_exists($clave, $escrito)) {
                $this->error("{$clave}: la base la tiene y falta en esquema.json.");
            } elseif ($escrito[$clave] !== $esquema[$clave]) {
                $this->error("{$clave}: difiere.");
                $this->line('  esquema.json: '.$escrito[$clave]);
                $this->line('  base:         '.$esquema[$clave]);
            } else {
                continue;
            }
            $difiere = true;
        }

        if ($difiere) {
            $this->error('esquema.json no coincide con el nucleo de la base migrada: php artisan nutrition:esquema --escribir');

            return self::FAILURE;
        }
        $this->info('esquema.json coincide con el nucleo de la base migrada ('.count($esquema).' tablas).');

        return self::SUCCESS;
    }
}
