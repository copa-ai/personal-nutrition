<?php

namespace App\Services\Menu;

use App\Models\ConsumoMenu;
use App\Models\Menu;
use App\Services\Claude\Tarifas;

/**
 * Cifras de la pestaña «Consumo» de un menú. Sale del libro consumos_menu (costes congelados al registrar cada respuesta).
 * Menus importados antes de que existiera el libro no tienen filas: se estiman con las tarifas actuales y se marca `congelado = false`.
 */
class ResumenConsumo
{
    private const SUMAS = ['input_tokens', 'output_tokens', 'cache_read', 'cache_creation', 'thinking_tokens', 'web_search', 'web_fetch'];

    /**
     * @return array{congelado: bool, totales: array<string, int>, costes: ?array<string, float>, sin_tarifa: int,
     *               por_fase: list<array<string, mixed>>, por_modelo: list<array<string, mixed>>}
     */
    public static function de(Menu $e): array
    {
        if (! ConsumoMenu::query()->where('menu_id', $e->id)->exists()) {
            return self::estimado($e);
        }

        $sumas = implode(', ', array_map(fn ($c) => "SUM($c) as $c", self::SUMAS));
        $agrupar = fn (string $expresion) => ConsumoMenu::query()->where('menu_id', $e->id)
            ->selectRaw("$expresion as clave, COUNT(*) as respuestas, $sumas, SUM(coste_total) as coste_total, SUM(CASE WHEN precios IS NULL THEN 1 ELSE 0 END) as sin_tarifa")
            ->groupBy('clave')->orderBy('clave')->get();
        $fila = fn ($g) => array_map('intval', array_intersect_key($g->getAttributes(), array_flip(self::SUMAS)))
            + ['respuestas' => (int) $g->respuestas, 'coste' => (int) $g->sin_tarifa < (int) $g->respuestas ? (float) $g->coste_total : null];

        $porModelo = $agrupar("COALESCE(modelo, 'desconocido')")->map(function ($g) use ($e, $fila) {
            $tarifas = ConsumoMenu::query()->where('menu_id', $e->id)->whereRaw("COALESCE(modelo, 'desconocido') = ?", [$g->clave])
                ->whereNotNull('precios')->pluck('precios')->unique(fn ($p) => json_encode($p))->values()->all();

            return ['modelo' => $g->clave, 'tarifas' => $tarifas] + $fila($g);
        })->all();

        $t = ConsumoMenu::query()->where('menu_id', $e->id)->selectRaw("$sumas, SUM(coste_entrada) as ce, SUM(coste_salida) as cs, SUM(coste_cache) as cc, "
            .'SUM(coste_web) as cw, SUM(coste_total) as ct, SUM(CASE WHEN precios IS NULL THEN 1 ELSE 0 END) as sin_tarifa, COUNT(*) as respuestas')->first();

        return [
            'congelado' => true,
            'totales' => array_map('intval', array_intersect_key($t->getAttributes(), array_flip(self::SUMAS))) + ['respuestas' => (int) $t->respuestas],
            'costes' => (int) $t->sin_tarifa < (int) $t->respuestas
                ? ['entrada' => (float) $t->ce, 'salida' => (float) $t->cs, 'cache' => (float) $t->cc, 'web' => (float) $t->cw, 'total' => (float) $t->ct] : null,
            'sin_tarifa' => (int) $t->sin_tarifa,
            'por_fase' => $agrupar("COALESCE(fase, 'sin fase')")->map(fn ($g) => ['fase' => $g->clave] + $fila($g))->all(),
            'por_modelo' => $porModelo,
        ];
    }

    /** Menu sin libro (importado antes): lo que trae `contenido.consumo` valorado con las tarifas de hoy. */
    private static function estimado(Menu $e): array
    {
        $c = $e->contenido['consumo'] ?? [];
        $tot = $c['totales'] ?? [];
        $tarifa = Tarifas::para(config('agente.modelo'));
        $costes = null;
        if ($tarifa) {
            $x = Tarifas::coste($tot, $tarifa);
            $costes = ['entrada' => $x['coste_entrada'], 'salida' => $x['coste_salida'], 'cache' => $x['coste_cache'], 'web' => $x['coste_web'], 'total' => $x['coste_total']];
        }
        $fase = fn (array $f) => array_map('intval', array_intersect_key($f, array_flip(self::SUMAS))) + ['respuestas' => (int) ($f['respuestas'] ?? 0),
            'coste' => $tarifa ? Tarifas::coste($f, $tarifa)['coste_total'] : null];

        return [
            'congelado' => false,
            'totales' => array_map('intval', array_intersect_key($tot, array_flip(self::SUMAS))) + ['respuestas' => (int) ($tot['respuestas'] ?? 0)],
            'costes' => $costes,
            'sin_tarifa' => 0,
            'por_fase' => collect($c['por_fase'] ?? [])->map(fn (array $f) => ['fase' => $f['fase'] ?? 'sin fase'] + $fase($f))->all(),
            'por_modelo' => [],
        ];
    }
}
