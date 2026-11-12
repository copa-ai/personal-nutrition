<?php

namespace App\Services\Compra;

use App\Enums\CategoriaComida;
use App\Models\Alimento;
use App\Models\Existencia;
use App\Models\Hogar;
use App\Models\Menu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * La lista de la compra del menu ACTIVO del hogar. Los ingredientes los suma Menu::necesidadesCompra() (la misma base que la lista del
 * PDF: opciones elegidas de comida y cena, con los cambios del hogar, y todas las tomas individuales); aqui se les resta lo que hay en
 * la despensa (existencias) y se agrupan por categoria del alimento, que aproxima los pasillos. Una fila por alimento y familia de unidad
 * (g y kg se suman, ml y l tambien; una unidad que no se conoce va aparte). Todas las cantidades internas van en la unidad base de su
 * familia (g, ml, ud o la unidad tal cual).
 *
 * @phpstan-type Uso array{plato: string, fecha: string, toma: string, perfil: ?string, cantidad: ?float, unidad: string}
 * @phpstan-type Fila array{item_id: string, nombre: string, alimento_id: ?string, categoria: string, familia: string, unidad: string, total: ?float, disponible: float, faltante: ?float, usos: list<array<string, mixed>>}
 */
class ListaCompra
{
    /** Orden de los pasillos (categorias del alimento). */
    private const PASILLOS = ['verdura', 'fruta', 'carne_roja', 'carne_blanca', 'pescado', 'marisco', 'lacteo', 'huevo', 'cereal', 'legumbre', 'frutos_secos', 'procesado', 'dulce', 'otro'];

    public function hogar(): ?Hogar
    {
        return Hogar::query()->orderBy('nombre')->first();
    }

    /** Identificador del mazo: cambia cuando cambia el menu activo, sus cambios o las opciones elegidas. Null si no hay menu con contenido. */
    public function idMazo(?Hogar $hogar): ?string
    {
        $menu = $hogar?->menuActivo;
        if (! $menu instanceof Menu || ($menu->contenidoVigente()['dias'] ?? []) === []) {
            return null;
        }

        return 'compra-'.substr(sha1($menu->id.'|'.json_encode($menu->contenidoVigente()).'|'.json_encode($menu->selecciones ?? [])), 0, 20);
    }

    /**
     * @return list<array<string, mixed>> filas (ver Fila), ya ordenadas por pasillo y nombre
     */
    public function calcular(?Hogar $hogar): array
    {
        $menu = $hogar?->menuActivo;
        if (! $hogar || ! $menu instanceof Menu) {
            return [];
        }

        $alimentos = [];
        foreach (Alimento::query()->get(['id', 'nombre', 'categoria', 'unidad']) as $a) {
            $alimentos[Ilustraciones::clave($a->nombre)] = $a;
        }
        $despensa = [];
        foreach (Existencia::query()->where('hogar_id', $hogar->id)->get(['alimento_id', 'cantidad', 'unidad']) as $e) {
            [$familia, $factor] = self::base((string) $e->unidad);
            $despensa[$e->alimento_id][$familia] = ($despensa[$e->alimento_id][$familia] ?? 0.0) + (float) $e->cantidad * $factor;
        }

        $filas = [];
        foreach ($menu->necesidadesCompra() as $n) {
            $clave = Ilustraciones::clave($n['nombre']);
            [$familia, $factor] = self::base($n['unidad']);
            $id = $clave.'|'.$familia;
            $alimento = $alimentos[$clave] ?? null;
            $fila = $filas[$id] ?? [
                'item_id' => 'c'.substr(sha1($id), 0, 20), 'nombre' => $alimento->nombre ?? $n['nombre'], 'alimento_id' => $alimento?->id,
                'categoria' => ($alimento->categoria ?? CategoriaComida::Otro)->value, 'familia' => $familia, 'unidad' => $n['unidad'],
                'total' => null, 'disponible' => 0.0, 'faltante' => null, 'usos' => [],
            ];
            if ($n['cantidad'] !== null) {
                $fila['total'] = ($fila['total'] ?? 0.0) + $n['cantidad'] * $factor;
            }
            foreach ($n['usos'] as $uso) {
                $fila['usos'][] = $uso + ['unidad' => $n['unidad']];
            }
            $filas[$id] = $fila;
        }

        $lista = [];
        foreach ($filas as $fila) {
            $fila['disponible'] = $fila['alimento_id'] === null ? 0.0 : (float) ($despensa[$fila['alimento_id']][$fila['familia']] ?? 0.0);
            if ($fila['total'] !== null) {
                $fila['faltante'] = round(max(0.0, $fila['total'] - $fila['disponible']), 4);
                if ($fila['faltante'] <= 0.0) {
                    continue;
                }
            }
            $lista[] = $fila;
        }

        usort($lista, function (array $a, array $b): int {
            $porPasillo = array_search($a['categoria'], self::PASILLOS, true) <=> array_search($b['categoria'], self::PASILLOS, true);

            return $porPasillo !== 0 ? $porPasillo : strcmp(Ilustraciones::clave($a['nombre']).$a['item_id'], Ilustraciones::clave($b['nombre']).$b['item_id']);
        });

        return $lista;
    }

    /** Titulo del grupo (pasillo aproximado) de una categoria. */
    public static function pasillo(string $categoria): string
    {
        return (CategoriaComida::tryFrom($categoria) ?? CategoriaComida::Otro)->getLabel();
    }

    /**
     * Familia de una unidad y cuanto vale la unidad en la base de la familia: g y kg (g), ml, cl y l (ml), unidades (ud).
     *
     * @return array{string, float}
     */
    public static function base(string $unidad): array
    {
        $u = mb_strtolower(trim($unidad));

        return match (true) {
            $u === 'g', $u === 'gr' => ['g', 1.0],
            $u === 'kg' => ['g', 1000.0],
            $u === 'ml' => ['ml', 1.0],
            $u === 'cl' => ['ml', 10.0],
            $u === 'l' => ['ml', 1000.0],
            in_array($u, ['', 'ud', 'uds', 'u', 'unidad', 'unidades'], true) => ['ud', 1.0],
            default => [$u, 1.0],
        };
    }

    /** Una cantidad en base de su familia, para leerla: 1500 g = «1,5 kg»; 250 ml = «250 ml»; 3 ud = «3 ud». */
    public static function formatear(float $base, string $familia, string $unidadOriginal = ''): string
    {
        [$valor, $unidad] = match ($familia) {
            'g' => $base >= 1000 ? [$base / 1000, 'kg'] : [$base, 'g'],
            'ml' => $base >= 1000 ? [$base / 1000, 'l'] : [$base, 'ml'],
            'ud' => [$base, 'ud'],
            default => [$base, trim($unidadOriginal) !== '' ? trim($unidadOriginal) : $familia],
        };
        $texto = $valor >= 100 ? number_format(round($valor), 0, ',', '') : rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');

        return $texto.' '.$unidad;
    }

    /** Un uso para el detalle de la tarjeta: «Lunes 2 · Comida · Lentejas: 200 g». */
    public static function textoUso(array $uso): string
    {
        $dia = ucfirst(CarbonImmutable::parse($uso['fecha'])->locale('es')->isoFormat('dddd D'));
        $cantidad = $uso['cantidad'] === null ? 'sin cantidad' : self::formatear($uso['cantidad'] * self::base((string) $uso['unidad'])[1], self::base((string) $uso['unidad'])[0], (string) $uso['unidad']);
        $quien = $uso['perfil'] !== null ? ' ('.$uso['perfil'].')' : '';

        return "{$dia} · {$uso['toma']}{$quien} · ".Str::limit(trim($uso['plato']), 80, '…').': '.$cantidad;
    }
}
