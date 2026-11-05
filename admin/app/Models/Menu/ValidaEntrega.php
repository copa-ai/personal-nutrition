<?php

namespace App\Models\Menu;

use App\Enums\CategoriaComida;
use Illuminate\Support\Collection;

trait ValidaEntrega
{
    /** Tomas individuales de cada perfil y día. */
    private const TOMAS_INDIVIDUALES = ['desayuno', 'almuerzo', 'merienda'];

    /**
     * Comprueba la entrega del agente contra el encargo con el que se lanzó (`$this->encargo`).
     *
     * Contrato (versión 3):
     *  - `entrega.version` = 3 (las entregas v2 ya guardadas siguen leyéndose; solo se validan las nuevas).
     *  - `dias`: una entrada por fecha de la semana, en orden, con `comida`, `cena` e `individuales`.
     *  - `comida`/`cena`: `categorias` (lista no vacía de categorías válidas) y `opciones` con entre `min` y `max` recetas.
     *    Si el hogar no cocina ese momento (minutos = null), ambas van vacías.
     *  - cada opción: `titulo`, `categorias` (mismo conjunto que la toma, sin importar el orden), `ingredientes` (con `nombre`),
     *    `duracion_previa_min` y `duracion_momento_min` (no más que el tiempo del hogar ese día).
     *    Opcional: `ajustes`, lista de `{perfil_id, texto}` con un perfil del encargo (sin repetir) y texto no vacío.
     *  - `individuales`: una entrada por perfil del encargo (`perfil_id`) con `desayuno`, `almuerzo` y `merienda`. Cada toma es una sola
     *    versión: `titulo`, `ingredientes` (con `nombre`), `duracion_previa_min` y `duracion_momento_min` (no más que el tiempo del hogar
     *    ese día si hay dato; con null no se limita).
     *  - `explicaciones`: una por perfil del encargo, con `perfil_id` y `texto`.
     *  - Tarea `editar`: misma entrega completa que `generar`. Tarea `intercambiar` (`encargo.tarea`): no lleva `dias`, sino `cambios`
     *    (ver erroresDeIntercambio), con las mismas reglas de opciones por toma.
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
        $perfiles = collect($encargo['perfiles'] ?? [])->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $tiempos = collect($encargo['hogar']['tiempos'] ?? [])->keyBy('fecha');
        $categorias = array_column(CategoriaComida::cases(), 'value');
        $errores = [];

        if (($encargo['tarea'] ?? 'generar') === 'intercambiar') {
            return $this->erroresDeIntercambio($datos, $fechas, $tiempos, $categorias, $min, $max, $perfiles);
        }

        $dias = $datos['dias'] ?? null;
        if (! is_array($dias) || ! array_is_list($dias)) {
            return ['Falta la lista «dias» en la entrega.'];
        }
        if (($datos['entrega']['version'] ?? null) !== 3) {
            $errores[] = 'La entrega tiene que ser de la versión 3 («entrega.version»: 3).';
        }
        if (array_map(fn ($d) => is_array($d) ? ($d['fecha'] ?? null) : null, $dias) !== $fechas) {
            $errores[] = 'Las fechas de «dias» no coinciden con la semana del encargo ('.implode(', ', $fechas).').';
        }

        foreach ($dias as $d) {
            $d = is_array($d) ? $d : [];
            $fecha = $d['fecha'] ?? '?';
            foreach (['comida', 'cena'] as $momento) {
                $minutos = $tiempos->get($fecha)["minutos_{$momento}"] ?? null;
                array_push($errores, ...$this->erroresDeToma($d[$momento] ?? null, "{$fecha} · {$momento}", $minutos, $categorias, $min, $max, $perfiles));
            }
            array_push($errores, ...$this->erroresDeIndividuales($d['individuales'] ?? null, $fecha, $tiempos->get($fecha) ?? [], $perfiles));
        }

        $explicados = $this->idsDePerfil($datos['explicaciones'] ?? null);
        if ($explicados !== $perfiles) {
            $errores[] = 'Tiene que haber exactamente una explicación por perfil en «explicaciones» (perfiles '.implode(', ', $perfiles).').';
        }
        if (collect(is_array($datos['explicaciones'] ?? null) ? $datos['explicaciones'] : [])->contains(fn ($x): bool => ! is_array($x) || $this->texto($x['texto'] ?? null) === '')) {
            $errores[] = 'Cada explicación necesita «texto».';
        }

        return $errores;
    }

    /**
     * Una toma de comida o cena: `categorias` y entre `min` y `max` `opciones` (vacías las dos si el hogar no cocina, `$minutos` = null).
     * La usan la entrega completa (generar y editar) y cada toma de un intercambio.
     *
     * @param  list<string>  $categorias  categorías válidas
     * @param  list<string>  $perfiles
     * @return list<string>
     */
    private function erroresDeToma(mixed $toma, string $donde, ?int $minutos, array $categorias, int $min, int $max, array $perfiles): array
    {
        $errores = [];
        $opciones = is_array($toma) ? ($toma['opciones'] ?? null) : null;
        if (! is_array($opciones) || ! array_is_list($opciones)) {
            return ["{$donde}: falta la lista «opciones»."];
        }
        $catsToma = $toma['categorias'] ?? null;
        if (! is_array($catsToma) || ! array_is_list($catsToma)) {
            $errores[] = "{$donde}: falta la lista «categorias».";
            $catsToma = null;
        } elseif ($minutos === null && $catsToma !== []) {
            $errores[] = "{$donde}: el hogar no cocina en ese momento, «categorias» debe ir vacío.";
        } elseif ($minutos !== null && ($catsToma === [] || array_diff($catsToma, $categorias) !== [])) {
            $errores[] = "{$donde}: «categorias» debe ser una lista no vacía de categorías válidas.";
        }
        if ($minutos === null && $opciones !== []) {
            $errores[] = "{$donde}: el hogar no cocina en ese momento, «opciones» debe ir vacío.";
        } elseif ($minutos !== null && (count($opciones) < $min || count($opciones) > $max)) {
            $errores[] = "{$donde}: hay ".count($opciones)." opciones y tienen que ser entre {$min} y {$max}.";
        }
        foreach (array_values($opciones) as $i => $o) {
            array_push($errores, ...$this->erroresDeOpcion($o, "{$donde} · opción ".($i + 1), $minutos, $categorias, $catsToma, $perfiles));
        }

        return $errores;
    }

    /**
     * Entrega de un intercambio: `cambios` es un objeto «fecha/momento» (comida o cena de una fecha de la semana en la que el hogar cocina)
     * -> toma completa, con las mismas reglas que una toma del menú. Puede estar vacío (nada que cambiar). No lleva `dias` ni `explicaciones`.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>  $fechas
     * @param  list<string>  $perfiles
     * @param  list<string>  $categorias
     * @return list<string>
     */
    private function erroresDeIntercambio(array $datos, array $fechas, Collection $tiempos, array $categorias, int $min, int $max, array $perfiles): array
    {
        $errores = [];
        if (($datos['entrega']['version'] ?? null) !== 3) {
            $errores[] = 'La entrega tiene que ser de la versión 3 («entrega.version»: 3).';
        }
        $cambios = $datos['cambios'] ?? null;
        if (! is_array($cambios) || ($cambios !== [] && array_is_list($cambios))) {
            return [...$errores, 'Falta el objeto «cambios» (clave «AAAA-MM-DD/comida» o «AAAA-MM-DD/cena»; vacío si no hay nada que cambiar).'];
        }
        foreach ($cambios as $clave => $toma) {
            [$fecha, $momento] = array_pad(explode('/', (string) $clave, 2), 2, '');
            if (! in_array($fecha, $fechas, true) || ! in_array($momento, ['comida', 'cena'], true)) {
                $errores[] = "{$clave}: la clave tiene que ser «fecha/comida» o «fecha/cena» con una fecha de la semana del encargo.";

                continue;
            }
            $minutos = $tiempos->get($fecha)["minutos_{$momento}"] ?? null;
            if ($minutos === null) {
                $errores[] = "{$clave}: el hogar no cocina en ese momento, no se puede cambiar.";

                continue;
            }
            array_push($errores, ...$this->erroresDeToma($toma, $clave, $minutos, $categorias, $min, $max, $perfiles));
        }

        return $errores;
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  list<string>  $categorias
     * @param  list<string>|null  $catsToma  categorías de la toma; null si ya se informó de que faltan
     * @param  list<string>  $perfiles  ids de perfil del encargo (para los `ajustes`)
     * @return list<string>
     */
    private function erroresDeOpcion(mixed $o, string $donde, ?int $minutos, array $categorias, ?array $catsToma, array $perfiles = []): array
    {
        if (! is_array($o)) {
            return ["{$donde}: la opción tiene que ser un objeto."];
        }
        $errores = $this->erroresDeReceta($o, $donde, $minutos);
        $cats = $o['categorias'] ?? null;
        if (! is_array($cats) || ! array_is_list($cats) || $cats === [] || array_diff($cats, $categorias) !== []) {
            $errores[] = "{$donde}: «categorias» debe ser una lista no vacía de categorías válidas.";
        } elseif ($catsToma !== null && $this->conjunto($cats) !== $this->conjunto($catsToma)) {
            $errores[] = "{$donde}: «categorias» debe tener las mismas categorías que la toma (".implode(', ', $catsToma).').';
        }

        array_push($errores, ...$this->erroresDeAjustes($o['ajustes'] ?? null, $donde, $perfiles));

        return $errores;
    }

    /**
     * `ajustes` es opcional (null = no hay): lista de `{perfil_id, texto}` con perfiles del encargo, sin repetir y con texto.
     *
     * @param  list<string>  $perfiles
     * @return list<string>
     */
    private function erroresDeAjustes(mixed $ajustes, string $donde, array $perfiles): array
    {
        if ($ajustes === null) {
            return [];
        }
        if (! is_array($ajustes) || ! array_is_list($ajustes)) {
            return ["{$donde}: «ajustes» debe ser una lista de objetos con «perfil_id» y «texto»."];
        }
        $errores = [];
        $vistos = [];
        foreach ($ajustes as $a) {
            $id = is_array($a) ? ($a['perfil_id'] ?? null) : null;
            if (! is_array($a) || ! is_string($id) || ! in_array($id, $perfiles, true)) {
                $errores[] = "{$donde}: cada ajuste necesita un «perfil_id» que sea un perfil del encargo (perfiles ".implode(', ', $perfiles).').';
            } elseif (in_array($id, $vistos, true)) {
                $errores[] = "{$donde}: el perfil {$id} tiene más de un ajuste.";
            } else {
                $vistos[] = $id;
            }
            if (! is_array($a) || $this->texto($a['texto'] ?? null) === '') {
                $errores[] = "{$donde}: cada ajuste necesita «texto».";
            }
        }

        return $errores;
    }

    /**
     * @param  array<string, mixed>  $tiempo
     * @param  list<string>  $perfiles
     * @return list<string>
     */
    private function erroresDeIndividuales(mixed $individuales, string $fecha, array $tiempo, array $perfiles): array
    {
        if (! is_array($individuales) || ! array_is_list($individuales)) {
            return ["{$fecha} · individuales: falta la lista «individuales»."];
        }
        $errores = [];
        if ($this->idsDePerfil($individuales) !== $perfiles) {
            $errores[] = "{$fecha} · individuales: tiene que haber exactamente una entrada por perfil (perfiles ".implode(', ', $perfiles).').';
        }
        foreach ($individuales as $ind) {
            if (! is_array($ind)) {
                $errores[] = "{$fecha} · individuales: cada entrada tiene que ser un objeto.";

                continue;
            }
            foreach (self::TOMAS_INDIVIDUALES as $momento) {
                $donde = "{$fecha} · perfil ".($ind['perfil_id'] ?? '?')." · {$momento}";
                $toma = $ind[$momento] ?? null;
                if (! is_array($toma)) {
                    $errores[] = "{$donde}: falta la toma.";

                    continue;
                }
                array_push($errores, ...$this->erroresDeReceta($toma, $donde, $tiempo["minutos_{$momento}"] ?? null));
            }
        }

        return $errores;
    }

    /**
     * Reglas comunes a una opción de comida/cena y a una toma individual.
     *
     * @param  array<string, mixed>  $o
     * @return list<string>
     */
    private function erroresDeReceta(array $o, string $donde, ?int $minutos): array
    {
        $errores = [];
        if ($this->texto($o['titulo'] ?? null) === '') {
            $errores[] = "{$donde}: falta el título.";
        }
        $ingredientes = $o['ingredientes'] ?? null;
        if (! is_array($ingredientes) || $ingredientes === [] || ! array_is_list($ingredientes)
            || collect($ingredientes)->contains(fn ($i): bool => ! is_array($i) || $this->texto($i['nombre'] ?? null) === '')) {
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

        return $errores;
    }

    /** Lista ordenada de `perfil_id` (UUID en texto) de las entradas que lo tengan; sirve para comparar con los del encargo. */
    private function idsDePerfil(mixed $entradas): array
    {
        return collect(is_array($entradas) ? $entradas : [])->map(fn ($e) => is_array($e) ? ($e['perfil_id'] ?? null) : null)
            ->map(fn ($id): string => is_string($id) ? $id : '')->sort()->values()->all();
    }

    /** Texto recortado; vacío si el valor no es una cadena (igual que `_texto` en agente/scripts/menu.py). */
    private function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }

    /** @param  list<string>  $cats */
    private function conjunto(array $cats): array
    {
        $unicas = array_values(array_unique($cats));
        sort($unicas);

        return $unicas;
    }
}
