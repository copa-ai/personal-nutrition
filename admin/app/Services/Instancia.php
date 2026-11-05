<?php

namespace App\Services;

use Illuminate\Support\Str;
use Throwable;

/**
 * Identificador de esta instancia (dispositivo) de Personal Nutrition: la auditoria lo anota en cada movimiento para saber desde
 * donde se hizo cada cambio cuando varias instancias comparten, o fusionan, la base de datos.
 *
 * Es el de NUTRITION_INSTANCIA si se da; si no, un UUID que se genera una vez y se guarda en storage/app/instancia (nunca en la base:
 * es lo que distingue a esta instancia de las demas). Null si ni siquiera se puede guardar.
 */
final class Instancia
{
    private static ?string $id = null;

    public static function id(): ?string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $configurada = config('nutrition.instancia');
        if (is_string($configurada) && $configurada !== '') {
            return self::$id = $configurada;
        }

        $ruta = storage_path('app/instancia');
        try {
            $guardada = is_file($ruta) ? trim((string) file_get_contents($ruta)) : '';
            if ($guardada === '') {
                $guardada = (string) Str::uuid7();
                file_put_contents($ruta, $guardada);
            }

            return self::$id = $guardada;
        } catch (Throwable) {
            return null;
        }
    }

    /** Olvida el id en memoria (las pruebas cambian la configuracion). */
    public static function olvidar(): void
    {
        self::$id = null;
    }
}
