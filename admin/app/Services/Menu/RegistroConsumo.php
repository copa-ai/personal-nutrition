<?php

namespace App\Services\Menu;

use App\Models\ConsumoMenu;
use App\Models\Menu;
use App\Services\Claude\Tarifas;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Guarda en consumos_menu las respuestas del modelo que trae un menú importado (`consumo.filas`, una por message.id).
 *  - Fila nueva: se calcula su coste con las tarifas de HOY para el modelo que respondio y se guardan tarifa y coste.
 *  - Fila ya registrada: no se recalcula con tarifas nuevas. Si sus contadores han crecido (la entrega es acumulativa y una
 *    respuesta puede completarse en una tanda posterior), se actualizan y el coste se rehace con SU tarifa guardada.
 *  - Una fila registrada sin tarifa (modelo desconocido entonces) se tarifica la primera vez que haya tarifa, y desde ahi se congela.
 */
class RegistroConsumo
{
    private const CONTADORES = ['input_tokens', 'output_tokens', 'cache_read', 'cache_creation', 'thinking_tokens', 'web_search', 'web_fetch'];

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return int filas nuevas o actualizadas
     */
    public static function registrar(Menu $menu, array $filas): int
    {
        $tocadas = 0;
        foreach ($filas as $f) {
            $mensaje = (string) ($f['mensaje_id'] ?? '');
            if ($mensaje === '') {
                continue;
            }
            $contadores = [];
            foreach (self::CONTADORES as $c) {
                $contadores[$c] = max(0, (int) ($f[$c] ?? 0));
            }
            $previo = ConsumoMenu::query()->where(['menu_id' => $menu->id, 'mensaje_id' => $mensaje])->first();
            if ($previo && ! self::cambiaron($previo, $contadores) && $previo->precios !== null) {
                continue;
            }

            $modelo = filled($f['modelo'] ?? null) ? (string) $f['modelo'] : $previo?->modelo;
            $precios = $previo?->precios;
            if ($precios === null && ($tarifa = Tarifas::para($modelo ?: config('agente.modelo'))) !== null) {
                $precios = Tarifas::instantanea($tarifa);
            }
            $costes = $precios !== null ? Tarifas::coste($contadores, $precios)
                : ['coste_entrada' => null, 'coste_salida' => null, 'coste_cache' => null, 'coste_web' => null, 'coste_total' => null];

            ConsumoMenu::query()->updateOrCreate(
                ['menu_id' => $menu->id, 'mensaje_id' => $mensaje],
                $contadores + $costes + ['precios' => $precios, 'modelo' => $modelo]
                + ($previo ? [] : ['ciclo' => $menu->ciclo ?: null, 'fase' => $f['fase'] ?? null, 'agente' => $f['agente'] ?? 'principal', 'fecha' => self::fecha($f['fecha'] ?? null)]),
            );
            $tocadas++;
        }

        return $tocadas;
    }

    private static function cambiaron(ConsumoMenu $previo, array $contadores): bool
    {
        foreach ($contadores as $campo => $valor) {
            if ((int) $previo->{$campo} !== $valor) {
                return true;
            }
        }

        return false;
    }

    private static function fecha(mixed $valor): ?string
    {
        try {
            return filled($valor) ? CarbonImmutable::parse($valor)->utc()->toDateTimeString() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
