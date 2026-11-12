<?php

namespace App\Services\Acciones;

use App\Models\Existencia;
use InvalidArgumentException;

/** Operaciones sobre lo que hay en casa que usa el chat (el panel edita cada existencia a mano). */
final class Despensa
{
    /**
     * Resta cantidades a varias existencias de una vez (lo gastado al cocinar). Una existencia que se queda en cero o menos se borra.
     *
     * @param  array<string, float>  $cantidades  id de existencia -> cantidad a restar (> 0)
     * @return array{restadas: int, agotadas: int}
     *
     * @throws InvalidArgumentException si alguna existencia ya no existe
     */
    public static function restar(array $cantidades): array
    {
        $restadas = 0;
        $agotadas = 0;
        foreach ($cantidades as $id => $cantidad) {
            $existencia = Existencia::query()->lockForUpdate()->find($id);
            if (! $existencia) {
                throw new InvalidArgumentException("La existencia {$id} ya no existe.");
            }
            $queda = round((float) $existencia->cantidad - $cantidad, 2);
            if ($queda <= 0) {
                $existencia->delete();
                $agotadas++;
            } else {
                $existencia->update(['cantidad' => $queda]);
            }
            $restadas++;
        }

        return ['restadas' => $restadas, 'agotadas' => $agotadas];
    }
}
