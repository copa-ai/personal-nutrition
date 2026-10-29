<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copia de seguridad de la base de datos (SQLite): exportarla a un fichero y volver a cargarla.
 *
 * Importar NO reemplaza el fichero de la base (la web y la cola lo tienen abierto): adjunta la copia y vuelca sus tablas
 * sobre las actuales dentro de una transaccion, de modo que el esquema sigue siendo el del codigo en marcha. Solo se copian
 * las columnas que existen en los dos lados y no se tocan las tablas efimeras (sesiones, cola, cache) ni `migrations`.
 */
class CopiaBase
{
    /** Tablas que no forman parte de la copia: estado de ejecucion, no datos de la persona. */
    private const OMITIR = ['migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens'];

    /** Una copia valida de esta aplicacion tiene al menos estas tablas. */
    private const REQUERIDAS = ['users', 'hogares'];

    public static function disponible(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** Escribe una instantanea consistente en un fichero temporal y devuelve su ruta (quien la pida debe borrarla). */
    public function exportar(): string
    {
        $this->exigirSqlite();
        $ruta = tempnam(sys_get_temp_dir(), 'copia');
        $pdo = DB::connection()->getPdo();
        $pdo->exec('VACUUM INTO '.$pdo->quote($ruta));

        return $ruta;
    }

    /**
     * Sustituye los datos actuales por los de la copia.
     *
     * @return array<string, int> filas importadas por tabla
     */
    public function importar(string $ruta): array
    {
        $this->exigirSqlite();
        $this->comprobarCabecera($ruta);

        $db = DB::connection();
        $pdo = $db->getPdo();
        $pdo->exec('ATTACH DATABASE '.$pdo->quote($ruta).' AS copia');

        try {
            $this->comprobarContenido();
            $tablas = array_values(array_diff($this->tablas('main'), self::OMITIR));
            $tablas = array_values(array_intersect($tablas, $this->tablas('copia')));

            $db->statement('PRAGMA foreign_keys = OFF');
            $resumen = [];
            $db->transaction(function () use ($db, $tablas, &$resumen) {
                $db->statement('PRAGMA defer_foreign_keys = ON');
                foreach ($tablas as $tabla) {
                    $columnas = array_values(array_intersect($this->columnas('main', $tabla), $this->columnas('copia', $tabla)));
                    $t = $this->id($tabla);
                    $db->statement("DELETE FROM main.$t");
                    if ($columnas) {
                        $lista = implode(', ', array_map($this->id(...), $columnas));
                        $db->statement("INSERT INTO main.$t ($lista) SELECT $lista FROM copia.$t");
                    }
                    $resumen[$tabla] = (int) $db->selectOne("SELECT COUNT(*) AS n FROM main.$t")->n;
                }
            });

            return $resumen;
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            $pdo->exec('DETACH DATABASE copia');
        }
    }

    private function exigirSqlite(): void
    {
        if (! self::disponible()) {
            throw new RuntimeException('Las copias de seguridad solo estan disponibles con SQLite.');
        }
    }

    private function comprobarCabecera(string $ruta): void
    {
        $f = is_file($ruta) ? fopen($ruta, 'rb') : false;
        $cabecera = $f ? fread($f, 16) : '';
        if ($f) {
            fclose($f);
        }
        if ($cabecera !== "SQLite format 3\0") {
            throw new RuntimeException('El archivo no es una copia de seguridad (no es una base SQLite).');
        }
    }

    private function comprobarContenido(): void
    {
        $estado = DB::selectOne('PRAGMA copia.integrity_check');
        if (($estado ? array_values((array) $estado)[0] : null) !== 'ok') {
            throw new RuntimeException('La copia esta danada (falla la comprobacion de integridad).');
        }
        $faltan = array_diff(self::REQUERIDAS, $this->tablas('copia'));
        if ($faltan) {
            throw new RuntimeException('El archivo no parece una copia de esta aplicacion (faltan: '.implode(', ', $faltan).').');
        }
    }

    /** @return list<string> */
    private function tablas(string $base): array
    {
        return array_map(
            fn ($f) => $f->name,
            DB::select("SELECT name FROM $base.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"),
        );
    }

    /** @return list<string> */
    private function columnas(string $base, string $tabla): array
    {
        return array_map(fn ($f) => $f->name, DB::select("PRAGMA $base.table_info(".$this->id($tabla).')'));
    }

    private function id(string $nombre): string
    {
        return '"'.str_replace('"', '""', $nombre).'"';
    }
}
