<?php

namespace App\Services\Compra;

use App\Enums\CategoriaComida;
use App\Models\Alimento;
use App\Models\Auditoria;
use App\Models\Existencia;
use App\Models\Hogar;
use Illuminate\Support\Facades\DB;

/**
 * Cambios de la despensa (tabla existencias) que provoca la lista de la compra: COMPRADO suma exactamente lo que faltaba y DESHACER lo
 * resta. Cada cambio devuelve un `efecto` que el mazo guarda con la decision, para deshacerlo con exactitud. Todo queda en la auditoria
 * (ademas de los triggers de la base, que anotan el cambio en la tabla).
 *
 * @phpstan-type Efecto array{existencia_id: string, alimento_id: string, delta: float, previa: float, creada: bool, unidad: string}
 */
class Despensa
{
    /**
     * Suma a la despensa lo que falta de una fila de la lista (ListaCompra). Null si no hay cantidad que sumar.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>|null el efecto (ver Efecto)
     */
    public function sumar(Hogar $hogar, array $fila): ?array
    {
        $falta = $fila['faltante'] ?? null;
        if (! is_numeric($falta) || (float) $falta <= 0.0) {
            return null;
        }

        return DB::transaction(function () use ($hogar, $fila, $falta): ?array {
            $alimento = $fila['alimento_id'] !== null ? Alimento::query()->find($fila['alimento_id']) : null;
            $alimento ??= Alimento::query()->firstOrCreate(['nombre' => $fila['nombre']], [
                'categoria' => CategoriaComida::tryFrom((string) $fila['categoria']) ?? CategoriaComida::Otro,
                'unidad' => in_array($fila['familia'], ['g', 'ml', 'ud'], true) ? $fila['familia'] : 'ud',
            ]);

            // Unidad de la fila de existencias: la del alimento si es de la misma familia; si no, la del ingrediente.
            $unidad = ListaCompra::base((string) $alimento->unidad)[0] === $fila['familia'] ? (string) $alimento->unidad : (string) $fila['unidad'];
            $unidad = mb_substr($unidad === '' ? $fila['familia'] : $unidad, 0, 10);
            $delta = round((float) $falta / ListaCompra::base($unidad)[1], 2);
            if ($delta <= 0.0) {
                return null;
            }

            $existencia = Existencia::query()->where('hogar_id', $hogar->id)->where('alimento_id', $alimento->id)->where('unidad', $unidad)
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            $creada = $existencia === null;
            $previa = $creada ? 0.0 : (float) $existencia->cantidad;
            if ($creada) {
                $existencia = new Existencia;
                $existencia->fill(['cantidad' => $delta, 'unidad' => $unidad, 'hogar_id' => $hogar->id, 'alimento_id' => $alimento->id]);
            } else {
                $existencia->cantidad = round($previa + $delta, 2);
            }
            $existencia->save();

            $efecto = ['existencia_id' => (string) $existencia->id, 'alimento_id' => (string) $alimento->id, 'delta' => $delta, 'previa' => $previa, 'creada' => $creada, 'unidad' => $unidad];
            Auditoria::registrar('cards.compra', 'Compra marcada en la lista de la compra', ['alimento' => $alimento->nombre] + $efecto);

            return $efecto;
        });
    }

    /**
     * Deshace un efecto: resta lo que se sumo, sin bajar de lo que habia antes y sin subir lo que hay ahora. Si la fila la creo la compra y
     * queda a 0, se borra. Si la fila ya no existe, no hay nada que hacer.
     *
     * @param  array<string, mixed>  $efecto
     */
    public function restar(array $efecto): void
    {
        DB::transaction(function () use ($efecto): void {
            $existencia = Existencia::query()->whereKey($efecto['existencia_id'])->lockForUpdate()->first();
            if ($existencia === null) {
                return;
            }
            $actual = (float) $existencia->cantidad;
            $nuevo = min($actual, max((float) $efecto['previa'], round($actual - (float) $efecto['delta'], 2)));
            if (! empty($efecto['creada']) && $nuevo <= 0.0) {
                $existencia->delete();
            } else {
                $existencia->cantidad = $nuevo;
                $existencia->save();
            }
            Auditoria::registrar('cards.compra_deshecha', 'Compra deshecha en la lista de la compra', $efecto + ['resultado' => $nuevo]);
        });
    }
}
