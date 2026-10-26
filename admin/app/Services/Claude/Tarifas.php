<?php

namespace App\Services\Claude;

/**
 * Tarifas de los modelos (config agente.precios, USD por millon de tokens; `busqueda_web` USD por busqueda) y calculo del coste
 * de una fila de consumo. Quien registra un consumo guarda la tarifa que uso (`instantanea`) para no recalcular con precios nuevos.
 */
final class Tarifas
{
    /** Tarifa de un modelo: coincidencia exacta o, si no, la clave mas larga que sea prefijo (claude-haiku-4-5-20251001 -> claude-haiku-4-5). */
    public static function para(?string $modelo): ?array
    {
        $precios = (array) config('agente.precios');
        $modelo = trim((string) $modelo);
        if ($modelo === '') {
            return null;
        }
        if (isset($precios[$modelo]) && is_array($precios[$modelo])) {
            return ['modelo_tarifa' => $modelo] + $precios[$modelo];
        }
        $mejor = null;
        foreach (array_keys($precios) as $clave) {
            if (str_starts_with($modelo, (string) $clave) && ($mejor === null || strlen((string) $clave) > strlen($mejor))) {
                $mejor = (string) $clave;
            }
        }

        return $mejor !== null && is_array($precios[$mejor]) ? ['modelo_tarifa' => $mejor] + $precios[$mejor] : null;
    }

    /** La tarifa tal como se guarda con el consumo, con la fecha en que se aplico. */
    public static function instantanea(array $tarifa): array
    {
        return array_merge(array_intersect_key($tarifa, array_flip(['modelo_tarifa', 'entrada', 'salida', 'cache_lectura', 'cache_escritura', 'busqueda_web'])),
            ['fecha' => now()->toDateString()]);
    }

    /**
     * @param  array  $f  contadores: input_tokens, output_tokens, cache_read, cache_creation, web_search
     * @return array{coste_entrada: float, coste_salida: float, coste_cache: float, coste_web: float, coste_total: float}
     *
     * Los tokens de razonamiento ya van dentro de la salida.
     */
    public static function coste(array $f, array $p): array
    {
        $entrada = (($f['input_tokens'] ?? 0) * ($p['entrada'] ?? 0)) / 1_000_000;
        $salida = (($f['output_tokens'] ?? 0) * ($p['salida'] ?? 0)) / 1_000_000;
        $cache = (($f['cache_read'] ?? 0) * ($p['cache_lectura'] ?? 0) + ($f['cache_creation'] ?? 0) * ($p['cache_escritura'] ?? 0)) / 1_000_000;
        $web = ($f['web_search'] ?? 0) * ($p['busqueda_web'] ?? 0);

        return ['coste_entrada' => $entrada, 'coste_salida' => $salida, 'coste_cache' => $cache, 'coste_web' => $web,
            'coste_total' => $entrada + $salida + $cache + $web];
    }
}
