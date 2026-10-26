<?php

namespace App\Models\Existencia;

use App\Models\Existencia;
use Carbon\CarbonInterface;

trait SumaStock
{
    /**
     * Suma una cantidad al stock del hogar. Si ya hay una existencia del mismo alimento y unidad, la incrementa y adelanta
     * su caducidad a la más próxima; si no, crea una nueva.
     */
    public static function sumar(int $hogarId, int $alimentoId, float $cantidad, string $unidad, ?CarbonInterface $caducaEl = null): Existencia
    {
        $existencia = Existencia::query()
            ->where('hogar_id', $hogarId)->where('alimento_id', $alimentoId)->where('unidad', $unidad)
            ->first();

        if ($existencia === null) {
            $existencia = new Existencia(['cantidad' => $cantidad, 'unidad' => $unidad, 'caduca_el' => $caducaEl]);
            $existencia->hogar_id = $hogarId;
            $existencia->alimento_id = $alimentoId;
            $existencia->save();

            return $existencia;
        }

        $existencia->cantidad = (float) $existencia->cantidad + $cantidad;
        if ($caducaEl !== null && ($existencia->caduca_el === null || $caducaEl->lt($existencia->caduca_el))) {
            $existencia->caduca_el = $caducaEl;
        }
        $existencia->save();

        return $existencia;
    }
}
