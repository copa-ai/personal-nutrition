<?php

namespace App\Services\Acciones;

use App\Enums\CategoriaComida;
use App\Models\Menu;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Edición a mano del contenido de un menú ya generado, sin pasar por el agente: cambiar los datos de una receta (opción) de una toma
 * y cambiar las categorías de una toma. Respeta el contrato de la entrega: todas las opciones de una toma comparten categorías.
 * Si la toma tiene un cambio por intercambio vigente (hogares.cambios), el mismo cambio se aplica allí para que se vea.
 */
final class EditaMenu
{
    /** Campos de una receta que se pueden editar a mano. */
    public const CAMPOS_OPCION = ['titulo', 'descripcion', 'nota', 'pasos', 'duracion_previa_min', 'duracion_momento_min'];

    /**
     * Cambia campos de una opción de comida o cena (índice desde 0).
     *
     * @param  array<string, mixed>  $cambios  claves de CAMPOS_OPCION ya validadas
     *
     * @throws InvalidArgumentException si la toma u opción no existen o el menú tiene un trabajo en curso
     */
    public static function editarOpcion(Menu $menu, string $fecha, string $momento, int $indice, array $cambios): void
    {
        self::comprobar($menu);
        DB::transaction(function () use ($menu, $fecha, $momento, $indice, $cambios): void {
            self::sobre($menu, $fecha, $momento, function (array $toma) use ($indice, $cambios, $fecha, $momento): array {
                if (! isset($toma['opciones'][$indice])) {
                    throw new InvalidArgumentException("La opción {$indice} no existe en esa toma ({$fecha} · {$momento}).");
                }
                $toma['opciones'][$indice] = array_replace($toma['opciones'][$indice], $cambios);

                return $toma;
            });
        });
    }

    /**
     * Cambia las categorías de una toma de comida o cena y las de todas sus opciones (tienen que ser el mismo conjunto).
     *
     * @param  list<string>  $categorias  valores de CategoriaComida, no vacía
     *
     * @throws InvalidArgumentException si la toma no existe (o el hogar no cocina en ese momento) o hay un trabajo en curso
     */
    public static function cambiarCategorias(Menu $menu, string $fecha, string $momento, array $categorias): void
    {
        self::comprobar($menu);
        if ($categorias === [] || array_diff($categorias, array_column(CategoriaComida::cases(), 'value')) !== []) {
            throw new InvalidArgumentException('Las categorías tienen que ser una lista no vacía de categorías válidas.');
        }
        DB::transaction(function () use ($menu, $fecha, $momento, $categorias): void {
            self::sobre($menu, $fecha, $momento, function (array $toma) use ($categorias): array {
                $toma['categorias'] = $categorias;
                foreach ($toma['opciones'] ?? [] as $i => $opcion) {
                    $toma['opciones'][$i]['categorias'] = $categorias;
                }

                return $toma;
            });
        });
    }

    /** @throws InvalidArgumentException */
    private static function comprobar(Menu $menu): void
    {
        if (empty($menu->contenido)) {
            throw new InvalidArgumentException('El menú todavía no se ha generado.');
        }
        if ($menu->estado->enCurso()) {
            throw new InvalidArgumentException('El menú tiene un trabajo en curso.');
        }
    }

    /**
     * Aplica `$cambiar` a la toma en el contenido del menú y, si hay un cambio por intercambio vigente para ella, también allí.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $cambiar
     */
    private static function sobre(Menu $menu, string $fecha, string $momento, callable $cambiar): void
    {
        if (! in_array($momento, ['comida', 'cena'], true)) {
            throw new InvalidArgumentException('Solo se editan las tomas de comida y cena.');
        }
        $contenido = $menu->contenido;
        $posicion = null;
        foreach ($contenido['dias'] ?? [] as $i => $dia) {
            if (($dia['fecha'] ?? null) === $fecha) {
                $posicion = $i;
            }
        }
        if ($posicion === null || empty($contenido['dias'][$posicion][$momento]['opciones'])) {
            throw new InvalidArgumentException("No hay toma de {$momento} el {$fecha} (o el hogar no cocina en ese momento).");
        }
        $contenido['dias'][$posicion][$momento] = $cambiar($contenido['dias'][$posicion][$momento]);
        $menu->update(['contenido' => $contenido]);

        $hogar = $menu->hogar;
        $clave = "{$fecha}/{$momento}";
        if ($hogar && $hogar->menu_id === $menu->id && isset($hogar->cambios[$clave]) && is_array($hogar->cambios[$clave])) {
            $cambios = $hogar->cambios;
            $cambios[$clave] = $cambiar($cambios[$clave]);
            $hogar->forceFill(['cambios' => $cambios])->save();
        }
    }
}
