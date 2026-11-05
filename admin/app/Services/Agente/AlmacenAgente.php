<?php

namespace App\Services\Agente;

use App\Models\AgenteArchivo;
use App\Models\Menu;
use InvalidArgumentException;

/**
 * Los archivos del agente (encargos/, workspace/, base/) viven en la base de datos (tabla agente_archivos) para que cualquier
 * Laravel que comparta la base los vea, vaya o no su agente en el mismo dispositivo.
 *
 * El runner no tiene credenciales de la base: la administracion le manda los archivos de entrada en la peticion que lanza la
 * ejecucion y recoge los de salida por HTTP al terminar (docker/agente/runner.py), y es la administracion quien los guarda aqui.
 *
 * Activo solo con AGENTE_ALMACEN=base. Sin el, todo son no-op y se trabaja directamente sobre la carpeta (desarrollo y pruebas).
 */
class AlmacenAgente
{
    /** Carpetas que se le mandan al agente antes de ejecutar (administracion -> agente). */
    public const ENTRADA = ['encargos/', 'base/'];

    /** Lo que se guarda al terminar (agente -> administracion). */
    public const SALIDA = ['workspace/', 'base/'];

    /** Tamaño maximo de un archivo del agente (por la peticion HTTP al runner y la memoria de PHP). */
    private const MAX_BYTES = 8_000_000;

    public static function activo(): bool
    {
        return config('agente.almacen') === 'base';
    }

    /** Ruta relativa valida: sin «..», sin rutas absolutas y dentro de las carpetas que se intercambian. */
    public static function rutaValida(string $ruta, array $carpetas): bool
    {
        if ($ruta === '' || str_contains($ruta, '..') || str_contains($ruta, '\\') || str_starts_with($ruta, '/') || str_contains($ruta, "\0")) {
            return false;
        }
        foreach ($carpetas as $carpeta) {
            if (str_starts_with($ruta, $carpeta)) {
                return true;
            }
        }

        return false;
    }

    public static function guardar(string $ruta, string $contenido): void
    {
        if (strlen($contenido) > self::MAX_BYTES) {
            throw new InvalidArgumentException("El archivo {$ruta} del agente es demasiado grande.");
        }
        AgenteArchivo::query()->updateOrCreate(['ruta' => $ruta], ['contenido' => base64_encode($contenido)]);
    }

    public static function leer(string $ruta): ?string
    {
        $c = AgenteArchivo::query()->where('ruta', $ruta)->value('contenido');

        return $c === null ? null : (string) base64_decode($c, true);
    }

    public static function borrar(string $ruta): void
    {
        static::activo() && AgenteArchivo::query()->where('ruta', $ruta)->delete();
    }

    /** Sube carpeta/$relativa a la base (si existe). */
    public static function subir(string $carpeta, string $relativa): void
    {
        $ruta = rtrim($carpeta, '/\\').'/'.ltrim($relativa, '/');
        if (static::activo() && is_file($ruta)) {
            static::guardar($relativa, (string) file_get_contents($ruta));
        }
    }

    /** Baja $relativa de la base a carpeta/$relativa. False si no esta. */
    public static function bajar(string $carpeta, string $relativa): bool
    {
        if (! static::activo() || ($contenido = static::leer($relativa)) === null) {
            return false;
        }
        $destino = rtrim($carpeta, '/\\').'/'.ltrim($relativa, '/');
        if (! is_dir(dirname($destino)) && ! @mkdir(dirname($destino), 0775, true) && ! is_dir(dirname($destino))) {
            throw new \RuntimeException('No se pudo crear la carpeta '.dirname($destino));
        }
        file_put_contents($destino, $contenido);

        return true;
    }

    /**
     * Lo que se le manda al runner al lanzar una ejecucion: encargos/ y base/ tal como estan en la base de datos.
     *
     * @return array<string, string> ruta => contenido en base64
     */
    public static function entrada(): array
    {
        $archivos = [];
        AgenteArchivo::query()->where(fn ($q) => collect(self::ENTRADA)->each(fn ($c) => $q->orWhere('ruta', 'like', $c.'%')))
            ->get(['ruta', 'contenido'])
            ->each(function (AgenteArchivo $a) use (&$archivos) {
                static::rutaValida($a->ruta, self::ENTRADA) && $archivos[$a->ruta] = $a->contenido;
            });

        return $archivos;
    }

    /**
     * Guarda lo que devuelve el runner al terminar (workspace/ y base/). Las rutas que no sean de esas carpetas se ignoran.
     *
     * @param  array<string, string>  $archivos  ruta => contenido en base64
     */
    public static function recibir(array $archivos): void
    {
        foreach ($archivos as $ruta => $b64) {
            if (is_string($ruta) && is_string($b64) && static::rutaValida($ruta, self::SALIDA) && ($c = base64_decode($b64, true)) !== false) {
                static::guardar($ruta, $c);
            }
        }
    }

    // --- Registro (log) de una tanda: se guarda tambien aqui para que se vea desde cualquier dispositivo ---

    public static function rutaRegistro(string $menuId): string
    {
        return "workspace/encargo-{$menuId}.log";
    }

    /** Ultimos $bytes del registro del trabajo, de la base (si el almacen esta activo) o del archivo local. Null si no hay. */
    public static function registro(Menu $menu, int $bytes = 60000): ?string
    {
        if (static::activo()) {
            $texto = static::leer(static::rutaRegistro($menu->id));

            return $texto === null ? null : substr($texto, -$bytes);
        }
        if (! $menu->archivo_log || ! is_file($menu->archivo_log)) {
            return null;
        }
        $f = fopen($menu->archivo_log, 'rb');
        fseek($f, max(0, filesize($menu->archivo_log) - $bytes));
        $texto = (string) stream_get_contents($f);
        fclose($f);

        return $texto;
    }
}
