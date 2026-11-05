<?php

namespace App\Services\BaseCompartida;

/**
 * La base en la que escribe este dispositivo ahora. `nutrition:base-datos` (al arrancar) y `nutrition:vigilar-base` (despues) dejan su
 * direccion en un fichero JSON (`{host, port, cluster}`, el de NUTRITION_BASE_ESTADO); la aplicacion lo lee y, si cambia, se reconecta.
 * Asi el servicio no se reinicia al cambiar de base.
 */
class EstadoBase
{
    private static ?string $aplicada = null;

    /** Fichero de estado; null si no hay (desarrollo y pruebas: se usa DB_HOST/DB_PORT). */
    public static function fichero(): ?string
    {
        $ruta = config('nutrition.base_estado');

        return is_string($ruta) && $ruta !== '' ? $ruta : null;
    }

    /** @return array{host: string, port: int, cluster: string}|null */
    public static function leer(?string $fichero = null): ?array
    {
        $fichero ??= self::fichero();
        $datos = $fichero !== null && is_file($fichero) ? json_decode((string) file_get_contents($fichero), true) : null;
        if (! is_array($datos) || ! is_string($datos['host'] ?? null) || ! is_int($datos['port'] ?? null)) {
            return null;
        }

        return ['host' => $datos['host'], 'port' => $datos['port'], 'cluster' => (string) ($datos['cluster'] ?? '')];
    }

    /** Escribe el estado de forma atomica (el lector nunca ve el fichero a medias). */
    public static function escribir(string $fichero, string $host, int $puerto, string $cluster): void
    {
        file_put_contents($fichero.'.tmp', json_encode(['host' => $host, 'port' => $puerto, 'cluster' => $cluster]));
        rename($fichero.'.tmp', $fichero);
    }

    /** Si el fichero apunta a otra base que la conectada, cambia la configuracion y cierra la conexion para que la siguiente consulta use la nueva. */
    public static function aplicar(): void
    {
        $estado = self::leer();
        if ($estado === null) {
            return;
        }
        $direccion = "{$estado['host']}:{$estado['port']}";
        if ($direccion === (self::$aplicada ?? config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port'))) {
            return;
        }
        config(['database.connections.pgsql.host' => $estado['host'], 'database.connections.pgsql.port' => $estado['port']]);
        app('db')->purge('pgsql');
        self::$aplicada = $direccion;
    }

    /** Olvida lo aplicado (las pruebas). */
    public static function olvidar(): void
    {
        self::$aplicada = null;
    }
}
