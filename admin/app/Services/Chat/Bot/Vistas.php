<?php

namespace App\Services\Chat\Bot;

use App\Models\Existencia;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Services\Chat\Marcado\Reglas;
use App\Services\Chat\Marcado\Widget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Los widgets (bloques html) del bot: menú de un día, de la semana, despensa y confirmación de un registro. Todo con Widget. */
final class Vistas
{
    private const ROJO = '#b91c1c';

    private const AMBAR = '#b45309';

    private const ETIQUETAS = ['desayuno' => 'Desayuno', 'almuerzo' => 'Almuerzo', 'comida' => 'Comida', 'merienda' => 'Merienda', 'cena' => 'Cena'];

    /** El plato que se sigue en una toma de comida o cena y, si hay, las alternativas. @param  array<string, mixed>  $toma */
    private static function textoDeToma(array $toma, bool $conAlternativas = true): string
    {
        $opciones = $toma['opciones'] ?? [];
        if ($opciones === []) {
            return 'No se cocina';
        }
        $elegida = (int) ($toma['elegida'] ?? 0);
        $texto = Reglas::cortar((string) ($opciones[$elegida]['titulo'] ?? 'Sin título'), 80);
        if ($conAlternativas && count($opciones) > 1) {
            $otras = [];
            foreach ($opciones as $i => $opcion) {
                if ($i !== $elegida) {
                    $otras[] = Reglas::cortar((string) ($opcion['titulo'] ?? ''), 60);
                }
            }
            $texto .= ' (o: '.implode(' / ', array_slice(array_filter($otras), 0, 3)).')';
        }

        return $texto.(! empty($toma['cambiada']) ? ' · cambiada' : '');
    }

    /**
     * El menú de un día: comida y cena (comunes al hogar) y, por cada perfil mostrado, sus tomas individuales.
     *
     * @param  array<string, mixed>  $dia  un elemento de Menu::tomas()
     * @param  Collection<int, Perfil>  $perfiles  los perfiles que se muestran (vacío: solo lo común)
     * @param  list<string>  $momentos  vacío = todos los de un día
     */
    public static function dia(Menu $menu, array $dia, Collection $perfiles, array $momentos): Widget
    {
        $quiere = fn (string $momento): bool => $momentos === [] || in_array($momento, $momentos, true);
        $widget = Widget::nuevo()->titulo((string) $dia['titulo'], 3);

        $comunes = [];
        foreach (['comida', 'cena'] as $momento) {
            if ($quiere($momento) && isset($dia['tomas'][$momento])) {
                $comunes[] = [Widget::resaltar(self::ETIQUETAS[$momento], null, true), self::textoDeToma($dia['tomas'][$momento])];
            }
        }
        if ($comunes !== []) {
            $widget->tabla([], $comunes);
        }

        foreach ($perfiles as $perfil) {
            $filas = [];
            foreach ($dia['individuales'] ?? [] as $individual) {
                if ((int) ($individual['perfil_id'] ?? 0) !== $perfil->id) {
                    continue;
                }
                foreach (['desayuno', 'almuerzo', 'merienda'] as $momento) {
                    if ($quiere($momento) && isset($individual['tomas'][$momento])) {
                        $filas[] = [Widget::resaltar(self::ETIQUETAS[$momento], null, true), Reglas::cortar((string) ($individual['tomas'][$momento]['toma']['titulo'] ?? '—'), 80)];
                    }
                }
            }
            if ($filas !== []) {
                $widget->titulo($perfil->nombre, 4)->tabla([], $filas);
            }
        }

        return $widget->parrafo(Widget::resaltar('Menú: ', null, false), Reglas::cortar((string) $menu->nombre, 80));
    }

    /** Todo el menú, un día por fila (comida y cena). @param  list<array<string, mixed>>  $dias */
    public static function semana(Menu $menu, array $dias): Widget
    {
        $filas = [];
        foreach ($dias as $dia) {
            $filas[] = [
                Widget::resaltar((string) $dia['corto'], null, true),
                self::textoDeToma($dia['tomas']['comida'] ?? [], false),
                self::textoDeToma($dia['tomas']['cena'] ?? [], false),
            ];
        }

        return Widget::nuevo()->titulo($menu->titulo(), 3)->tabla(['Día', 'Comida', 'Cena'], $filas);
    }

    /**
     * Lo que hay en casa y lo que caduca pronto.
     *
     * @param  Collection<int, Existencia>  $existencias  con su alimento
     */
    public static function despensa(Collection $existencias, CarbonImmutable $hoy, int $diasAviso = 7, int $maxFilas = 40): Widget
    {
        $hay = $existencias->filter(fn (Existencia $e): bool => (float) $e->cantidad > 0)->sortBy(fn (Existencia $e): string => mb_strtolower((string) $e->alimento?->nombre))->values();
        $limite = $hoy->startOfDay()->addDays($diasAviso);
        $pronto = $hay->filter(fn (Existencia $e): bool => $e->caduca_el !== null && CarbonImmutable::parse($e->caduca_el)->startOfDay()->lessThanOrEqualTo($limite))
            ->sortBy(fn (Existencia $e): string => CarbonImmutable::parse($e->caduca_el)->toDateString())->values();

        $widget = Widget::nuevo()->titulo('Despensa', 3);
        if ($hay->isEmpty()) {
            return $widget->parrafo('No hay nada apuntado en la despensa.');
        }
        if ($pronto->isNotEmpty()) {
            $filas = [];
            foreach ($pronto->take(15) as $e) {
                $caduca = CarbonImmutable::parse($e->caduca_el)->startOfDay();
                $dias = (int) $hoy->startOfDay()->diffInDays($caduca, false);
                $cuando = $dias < 0 ? 'caducó hace '.(-$dias).' '.(-$dias === 1 ? 'día' : 'días') : ($dias === 0 ? 'caduca hoy' : ($dias === 1 ? 'caduca mañana' : "caduca en {$dias} días"));
                $filas[] = [Reglas::cortar((string) $e->alimento?->nombre, 50), self::cantidad($e), Widget::resaltar($cuando.' ('.$caduca->format('d/m').')', $dias < 0 ? self::ROJO : self::AMBAR, true)];
            }
            $widget->titulo('Caduca pronto', 4)->tabla(['Alimento', 'Cantidad', 'Caducidad'], $filas);
        }
        $filas = [];
        foreach ($hay->take($maxFilas) as $e) {
            $filas[] = [Reglas::cortar((string) $e->alimento?->nombre, 50), self::cantidad($e), $e->caduca_el ? CarbonImmutable::parse($e->caduca_el)->format('d/m/Y') : '—'];
        }
        $widget->titulo('Lo que hay', 4)->tabla(['Alimento', 'Cantidad', 'Caduca'], $filas);
        if ($hay->count() > $maxFilas) {
            $widget->parrafo('… y '.($hay->count() - $maxFilas).' más.');
        }

        return $widget;
    }

    private static function cantidad(Existencia $e): string
    {
        $numero = rtrim(rtrim(number_format((float) $e->cantidad, 2, ',', ''), '0'), ',');

        return $numero.' '.$e->unidad;
    }

    /** Confirmación de una comida registrada. */
    public static function registrada(RegistroComida $registro, Perfil $perfil, bool $conFoto): Widget
    {
        return Widget::nuevo()->titulo('Comida registrada', 3)->tabla([], [
            [Widget::resaltar('Perfil', null, true), $perfil->nombre],
            [Widget::resaltar('Momento', null, true), $registro->momento->getLabel()],
            [Widget::resaltar('Día', null, true), $registro->fecha->format('d/m/Y')],
            [Widget::resaltar('Qué', null, true), Reglas::cortar((string) $registro->descripcion, 300)],
            [Widget::resaltar('Origen', null, true), $registro->origen->getLabel()],
            [Widget::resaltar('Foto', null, true), $conFoto ? 'Guardada' : '—'],
        ]);
    }
}
