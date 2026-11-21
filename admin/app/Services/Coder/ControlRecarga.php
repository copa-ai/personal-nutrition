<?php

namespace App\Services\Coder;

use RuntimeException;

/**
 * The panel side of the reload watcher (docker/servicio/recarga.sh).
 * The panel and the watcher talk through small files in config coder.directorio. This class writes the flags and reads recarga.json.
 *
 * Files that the panel writes: auto-recarga, recargar-ahora, restaurar-original. The watcher removes the two flags when it acts.
 * File that the panel reads: recarga.json. It can be missing (the watcher did not start) or bad (a crash). Then `estado` gives defaults.
 */
class ControlRecarga
{
    public const ESTADOS = ['vigilando', 'pendiente', 'revisando', 'recargando', 'restaurando', 'error'];

    public const RESULTADOS = ['ninguno', 'ok', 'error_sintaxis', 'error_arranque', 'error_salud', 'revertido', 'restaurado_original'];

    /** Auto reload is ON when the file is missing. The watcher reads it with the same rule (see recarga.sh). */
    public function autoActivo(): bool
    {
        $ruta = $this->ruta('auto-recarga');
        $texto = is_file($ruta) ? strtolower(preg_replace('/\s+/', '', (string) @file_get_contents($ruta)) ?? '') : '';

        return ! in_array($texto, ['0', 'off', 'no', 'false'], true);
    }

    public function fijarAuto(bool $activo): void
    {
        $this->escribir('auto-recarga', $activo ? "1\n" : "0\n");
    }

    /** Ask the watcher for one reload now. It works when auto reload is OFF too. */
    public function pedirRecarga(): void
    {
        $this->escribir('recargar-ahora', "1\n");
    }

    /** Ask the watcher to restore the original code and then reload. */
    public function pedirRestaurar(): void
    {
        $this->escribir('restaurar-original', "1\n");
    }

    public function recargaPedida(): bool
    {
        return is_file($this->ruta('recargar-ahora'));
    }

    public function restauracionPedida(): bool
    {
        return is_file($this->ruta('restaurar-original'));
    }

    /**
     * The status of the watcher. Every field has a type and a default, so the view never fails on a missing or wrong field.
     *
     * @return array{disponible: bool, estado: string, resultado: string, mensaje: string, fecha: string|null, pendiente: bool, cambios: int|null, igual_original: bool|null, arbol_vivo: string|null, ultima_buena: string|null, recargas: int}
     */
    public function estado(): array
    {
        $vacio = [
            'disponible' => false, 'estado' => 'vigilando', 'resultado' => 'ninguno', 'mensaje' => '', 'fecha' => null,
            'pendiente' => false, 'cambios' => null, 'igual_original' => null, 'arbol_vivo' => null, 'ultima_buena' => null, 'recargas' => 0,
        ];
        $ruta = $this->ruta('recarga.json');
        $texto = is_file($ruta) ? @file_get_contents($ruta) : false;
        if ($texto === false || strlen($texto) > 65536) {
            return $vacio;
        }
        $d = json_decode($texto, true);
        if (! is_array($d)) {
            return $vacio;
        }

        $estado = $d['estado'] ?? null;
        $resultado = $d['resultado'] ?? null;
        $mensaje = is_string($d['mensaje'] ?? null) ? mb_substr(preg_replace('/[[:cntrl:]]+/u', ' ', $d['mensaje']) ?? '', 0, 400) : '';
        $arbol = $d['arbol_vivo'] ?? null;

        return [
            'disponible' => true,
            'estado' => is_string($estado) && in_array($estado, self::ESTADOS, true) ? $estado : 'vigilando',
            'resultado' => is_string($resultado) && in_array($resultado, self::RESULTADOS, true) ? $resultado : 'ninguno',
            'mensaje' => $mensaje,
            'fecha' => self::fecha($d['fecha'] ?? null),
            'pendiente' => ($d['pendiente'] ?? false) === true,
            'cambios' => is_int($d['cambios'] ?? null) && $d['cambios'] >= 0 ? $d['cambios'] : null,
            'igual_original' => is_bool($d['igual_original'] ?? null) ? $d['igual_original'] : null,
            'arbol_vivo' => is_string($arbol) && preg_match('/^[0-9a-f]{64}$/', $arbol) === 1 ? $arbol : null,
            'ultima_buena' => self::fecha($d['ultima_buena'] ?? null),
            'recargas' => is_int($d['recargas'] ?? null) && $d['recargas'] >= 0 ? $d['recargas'] : 0,
        ];
    }

    /** An ISO 8601 date as a string, or null. */
    private static function fecha(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}T[\d:.]+Z?$/', $valor) === 1 ? $valor : null;
    }

    private function escribir(string $nombre, string $contenido): void
    {
        $carpeta = rtrim((string) config('coder.directorio'), '/');
        if (! is_dir($carpeta)) {
            throw new CoderException('No existe la carpeta de control del Coder ('.$carpeta.'). El servicio no ha terminado de arrancar.');
        }
        try {
            Coder::escribirAtomico($carpeta.'/'.$nombre, $contenido, 0644);
        } catch (RuntimeException $e) {
            throw new CoderException('No se pudo escribir la orden para el vigilante: '.$e->getMessage());
        }
    }

    private function ruta(string $nombre): string
    {
        return rtrim((string) config('coder.directorio'), '/').'/'.$nombre;
    }
}
