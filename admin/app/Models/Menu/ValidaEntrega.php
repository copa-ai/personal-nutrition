<?php

namespace App\Models\Menu;

use App\Enums\CategoriaComida;

trait ValidaEntrega
{
    /**
     * Comprueba la entrega del agente contra el encargo con el que se lanzó (`$this->encargo`).
     *
     * Contrato (versión 1):
     *  - `dias`: una entrada por fecha de la semana, en orden, con `comida` y `cena`.
     *  - `comida`/`cena`: `opciones` con entre `min` y `max` recetas. Si el hogar no cocina ese momento (minutos = null), `opciones` va vacío.
     *  - cada opción: `titulo`, `categorias`, `ingredientes` (con `nombre`), `duracion_previa_min`, `duracion_momento_min`
     *    (no más que el tiempo del hogar ese día) y `adaptaciones`: una por cada perfil del encargo, con `perfil_id` y `cambios`.
     *
     * @param  array<string, mixed>  $datos
     * @return list<string> errores en castellano; vacío si la entrega es válida
     */
    public function validarEntrega(array $datos): array
    {
        $encargo = $this->encargo ?? [];
        $fechas = $encargo['semana']['fechas'] ?? [];
        $min = (int) ($encargo['opciones_por_comida']['min'] ?? 2);
        $max = (int) ($encargo['opciones_por_comida']['max'] ?? 3);
        $perfiles = collect($encargo['perfiles'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $tiempos = collect($encargo['hogar']['tiempos'] ?? [])->keyBy('fecha');
        $categorias = array_column(CategoriaComida::cases(), 'value');
        $errores = [];

        $dias = $datos['dias'] ?? null;
        if (! is_array($dias) || ! array_is_list($dias)) {
            return ['Falta la lista «dias» en la entrega.'];
        }
        if (array_column($dias, 'fecha') !== $fechas) {
            $errores[] = 'Las fechas de «dias» no coinciden con la semana del encargo ('.implode(', ', $fechas).').';
        }

        foreach ($dias as $d) {
            $fecha = $d['fecha'] ?? '?';
            foreach (['comida', 'cena'] as $momento) {
                $donde = "{$fecha} · {$momento}";
                $opciones = $d[$momento]['opciones'] ?? null;
                $minutos = $tiempos->get($fecha)["minutos_{$momento}"] ?? null;
                if (! is_array($opciones)) {
                    $errores[] = "{$donde}: falta la lista «opciones».";

                    continue;
                }
                if ($minutos === null && $opciones !== []) {
                    $errores[] = "{$donde}: el hogar no cocina en ese momento, «opciones» debe ir vacío.";
                } elseif ($minutos !== null && (count($opciones) < $min || count($opciones) > $max)) {
                    $errores[] = "{$donde}: hay ".count($opciones)." opciones y tienen que ser entre {$min} y {$max}.";
                }
                foreach (array_values($opciones) as $i => $o) {
                    array_push($errores, ...$this->erroresDeOpcion($o, "{$donde} · opción ".($i + 1), $minutos, $perfiles, $categorias));
                }
            }
        }

        return $errores;
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  list<int>  $perfiles
     * @param  list<string>  $categorias
     * @return list<string>
     */
    private function erroresDeOpcion(array $o, string $donde, ?int $minutos, array $perfiles, array $categorias): array
    {
        $errores = [];
        if (trim((string) ($o['titulo'] ?? '')) === '') {
            $errores[] = "{$donde}: falta el título.";
        }
        $cats = $o['categorias'] ?? null;
        if (! is_array($cats) || $cats === [] || array_diff($cats, $categorias) !== []) {
            $errores[] = "{$donde}: «categorias» debe ser una lista no vacía de categorías válidas.";
        }
        $ingredientes = $o['ingredientes'] ?? null;
        if (! is_array($ingredientes) || $ingredientes === [] || collect($ingredientes)->contains(fn ($i): bool => trim((string) ($i['nombre'] ?? '')) === '')) {
            $errores[] = "{$donde}: «ingredientes» debe ser una lista no vacía y cada uno con «nombre».";
        }
        foreach (['duracion_previa_min', 'duracion_momento_min'] as $campo) {
            if (! is_int($o[$campo] ?? null) || $o[$campo] < 0) {
                $errores[] = "{$donde}: «{$campo}» debe ser un número entero de minutos (0 o más).";
            }
        }
        if ($minutos !== null && is_int($o['duracion_momento_min'] ?? null) && $o['duracion_momento_min'] > $minutos) {
            $errores[] = "{$donde}: tarda {$o['duracion_momento_min']} min al momento y el hogar solo tiene {$minutos}.";
        }

        $adaptados = collect($o['adaptaciones'] ?? [])->pluck('perfil_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        if ($adaptados !== $perfiles) {
            $errores[] = "{$donde}: tiene que haber exactamente una adaptación por perfil (perfiles ".implode(', ', $perfiles).').';
        }
        if (collect($o['adaptaciones'] ?? [])->contains(fn ($a): bool => trim((string) ($a['cambios'] ?? '')) === '')) {
            $errores[] = "{$donde}: cada adaptación necesita «cambios» (puede ser «Sin cambios»).";
        }

        return $errores;
    }
}
