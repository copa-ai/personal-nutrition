<?php

namespace App\Services\Fusion;

use PDO;
use RuntimeException;

/**
 * Vuelca los datos de una base para fusionarlos en otra (App\Services\Fusion\Fusionador): un fichero NDJSON, una linea por tanda.
 *
 *   {"formato":"nutrition-datos","version":1,"origen":"...","esquema":"<huella>","creado":"..."}
 *   {"borrados":[{tabla, id, borrado_en, instancia}...]}
 *   {"tabla":"hogares","filas":[{...fila como json...}]}        (en el orden de fusion_tablas())
 *   {"auditorias":[{...}]}
 *
 * Todo sale de una sola instantanea (REPEATABLE READ de solo lectura): la copia es coherente aunque la base siga recibiendo escrituras.
 */
class Exportador
{
    public const FORMATO = 'nutrition-datos';

    public const VERSION = 1;

    /** Una tanda termina al llegar a estas filas o a estos bytes (los archivos del chat pueden pesar megas). */
    private const FILAS_POR_TANDA = 200;

    private const BYTES_POR_TANDA = 4_000_000;

    /**
     * @param  resource  $salida
     * @return array<string, int> filas escritas por tabla (y `borrados`, `auditorias`)
     */
    public function exportar(PDO $origen, $salida, string $instancia = ''): array
    {
        $cuenta = [];
        $origen->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        try {
            $this->escribir($salida, [
                'formato' => self::FORMATO,
                'version' => self::VERSION,
                'origen' => $instancia,
                'esquema' => (string) $origen->query('SELECT fusion_esquema()')->fetchColumn(),
                'creado' => date('c'),
            ]);

            $cuenta['borrados'] = $this->volcar($origen, $salida, 'SELECT to_jsonb(b) FROM borrados b ORDER BY b.tabla, b.id', 'borrados', null);
            $tablas = $this->lista((string) $origen->query('SELECT array_to_json(fusion_tablas())')->fetchColumn());
            foreach ($tablas as $tabla) {
                $orden = $tabla === 'chat_mensajes' ? 't.sequence' : 't.id';
                $cuenta[$tabla] = $this->volcar($origen, $salida, "SELECT to_jsonb(t) FROM {$this->identificador($tabla)} t ORDER BY {$orden}", 'filas', $tabla);
            }
            $cuenta['auditorias'] = $this->volcar($origen, $salida, 'SELECT to_jsonb(a) FROM auditorias a ORDER BY a.id', 'auditorias', null);
        } finally {
            $origen->exec('ROLLBACK');
        }

        return $cuenta;
    }

    /** @param  resource  $salida */
    private function volcar(PDO $origen, $salida, string $consulta, string $clave, ?string $tabla): int
    {
        $cursor = 'exportar_'.substr(md5($consulta), 0, 10);
        $origen->exec("DECLARE {$cursor} NO SCROLL CURSOR FOR {$consulta}");
        $total = 0;
        $tanda = [];
        $bytes = 0;
        try {
            while (true) {
                $filas = $origen->query("FETCH 50 FROM {$cursor}")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($filas as $json) {
                    $tanda[] = $json;
                    $bytes += strlen($json);
                    $total++;
                    if (count($tanda) >= self::FILAS_POR_TANDA || $bytes >= self::BYTES_POR_TANDA) {
                        $this->tanda($salida, $clave, $tabla, $tanda);
                        $tanda = [];
                        $bytes = 0;
                    }
                }
                if ($filas === []) {
                    break;
                }
            }
            $tanda === [] || $this->tanda($salida, $clave, $tabla, $tanda);
        } finally {
            $origen->exec("CLOSE {$cursor}");
        }

        return $total;
    }

    /**
     * @param  resource  $salida
     * @param  list<string>  $filas  cada una ya es un json
     */
    private function tanda($salida, string $clave, ?string $tabla, array $filas): void
    {
        $linea = '{'.($tabla === null ? '' : '"tabla":'.json_encode($tabla).',').'"'.$clave.'":['.implode(',', $filas).']}';
        $this->escribirLinea($salida, $linea);
    }

    /**
     * @param  resource  $salida
     * @param  array<string, mixed>  $datos
     */
    private function escribir($salida, array $datos): void
    {
        $this->escribirLinea($salida, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param  resource  $salida */
    private function escribirLinea($salida, string $linea): void
    {
        if (fwrite($salida, $linea."\n") === false) {
            throw new RuntimeException('No se pudo escribir el fichero de datos.');
        }
    }

    /** @return list<string> */
    private function lista(string $json): array
    {
        return array_values(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    private function identificador(string $nombre): string
    {
        return '"'.str_replace('"', '""', $nombre).'"';
    }
}
