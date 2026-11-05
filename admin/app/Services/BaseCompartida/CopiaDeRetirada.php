<?php

namespace App\Services\BaseCompartida;

use App\Services\CopiaBase;
use RuntimeException;
use Throwable;

/**
 * La copia que se guarda de una base justo antes de pararla (`nutrition:vigilar-base`): un volcado `.sql.gz` completo (el mismo de la
 * pagina de copia de seguridad, restaurable con psql sobre una base ya migrada) en `nutrition.baja.copias`. Si no se puede guardar entera, la
 * base no se para. Se conservan las ultimas `nutrition.baja.copias_guardadas`.
 */
class CopiaDeRetirada
{
    /**
     * @return string ruta del fichero guardado
     *
     * @throws RuntimeException si la copia no se pudo guardar entera
     */
    public function guardar(string $direccion, string $identificador): string
    {
        $carpeta = (string) config('nutrition.baja.copias');
        if (! is_dir($carpeta) && ! @mkdir($carpeta, 0700, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear {$carpeta}.");
        }

        $destino = $carpeta.'/retirada-'.now()->format('Y-m-d-His').'-'.preg_replace('/[^0-9A-Za-z]/', '', $identificador).'.sql.gz';
        $temporal = $destino.'.parcial';
        $flujo = @fopen($temporal, 'wb');
        if ($flujo === false) {
            throw new RuntimeException("No se pudo escribir {$temporal}.");
        }
        chmod($temporal, 0600);

        try {
            [$host, $puerto] = [str($direccion)->beforeLast(':')->toString(), (int) str($direccion)->afterLast(':')->toString()];
            $tablas = (new CopiaBase)->volcarGzip(function (string $trozo) use ($flujo): void {
                if (fwrite($flujo, $trozo) === false) {
                    throw new RuntimeException('No se pudo escribir la copia.');
                }
            }, Conexion::pdo($host, $puerto));
            fflush($flujo);
            fclose($flujo);
            if ($tablas < 1 || filesize($temporal) < 20 || ! $this->legible($temporal)) {
                throw new RuntimeException('La copia no esta completa.');
            }
            rename($temporal, $destino);
        } catch (Throwable $e) {
            is_resource($flujo) && fclose($flujo);
            @unlink($temporal);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), previous: $e);
        }

        $this->podar($carpeta);

        return $destino;
    }

    /** El gzip se descomprime entero y acaba con COMMIT (el volcado termino de escribirse). */
    private function legible(string $ruta): bool
    {
        $gz = gzopen($ruta, 'rb');
        if ($gz === false) {
            return false;
        }
        $cola = '';
        while (! gzeof($gz)) {
            $trozo = gzread($gz, 1 << 20);
            if ($trozo === false) {
                gzclose($gz);

                return false;
            }
            $cola = substr($cola.$trozo, -64);
        }
        gzclose($gz);

        return str_contains($cola, 'COMMIT;');
    }

    private function podar(string $carpeta): void
    {
        $copias = glob($carpeta.'/retirada-*.sql.gz') ?: [];
        usort($copias, fn (string $a, string $b): int => [filemtime($b), $b] <=> [filemtime($a), $a]);
        foreach (array_slice($copias, max(1, (int) config('nutrition.baja.copias_guardadas'))) as $vieja) {
            @unlink($vieja);
        }
    }
}
