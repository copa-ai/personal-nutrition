<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Enums\EstadoMenu;
use App\Models\Hogar;
use App\Models\Menu;
use App\Services\Acciones\EditaMenu;
use App\Services\Acciones\GestionMenus;
use App\Services\Chat\Claude\Rechazo;
use App\Services\Chat\Claude\Validadores;
use Carbon\CarbonImmutable;

/**
 * Menus: crear, generar, activar, elegir plato por toma, editar (con comentario o a mano), intercambiar y borrar.
 *   crear_menu {hogar, fecha_inicio, nombre?, generar?}      generar_menu {menu_id}       reintentar_generacion {menu_id}
 *   cancelar_generacion {menu_id, forzar?} (confirmacion)    activar_menu {menu_id} (confirmacion si sustituye a otro o descarta cambios)
 *   elegir_opcion {menu_id, fecha, momento: comida|cena, opcion}
 *   editar_menu_con_comentario {menu_id, comentario} (confirmacion: rehace el menu entero)
 *   intercambiar_menu {menu_id, comido}                      descartar_cambios_menu {menu_id} (confirmacion)
 *   editar_opcion_menu {menu_id, fecha, momento, opcion, titulo?, descripcion?, nota?, pasos?, duracion_previa_min?, duracion_momento_min?}
 *   cambiar_categorias_toma {menu_id, fecha, momento, categorias}      borrar_menu {menu_id} (confirmacion)
 * `opcion` es el indice de la opcion desde 0 (el de tomas.csv). Generar, editar con comentario e intercambiar tardan: quedan en cola.
 */
class GrupoMenus implements GrupoAcciones
{
    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'crear_menu' => ['tipo', 'hogar', 'fecha_inicio', 'nombre', 'generar'],
            'generar_menu' => ['tipo', 'menu_id'],
            'reintentar_generacion' => ['tipo', 'menu_id'],
            'cancelar_generacion' => ['tipo', 'menu_id', 'forzar'],
            'activar_menu' => ['tipo', 'menu_id'],
            'elegir_opcion' => ['tipo', 'menu_id', 'fecha', 'momento', 'opcion'],
            'editar_menu_con_comentario' => ['tipo', 'menu_id', 'comentario'],
            'intercambiar_menu' => ['tipo', 'menu_id', 'comido'],
            'descartar_cambios_menu' => ['tipo', 'menu_id'],
            'editar_opcion_menu' => ['tipo', 'menu_id', 'fecha', 'momento', 'opcion', 'titulo', 'descripcion', 'nota', 'pasos', 'duracion_previa_min', 'duracion_momento_min'],
            'cambiar_categorias_toma' => ['tipo', 'menu_id', 'fecha', 'momento', 'categorias'],
            'borrar_menu' => ['tipo', 'menu_id'],
        ];
    }

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'crear_menu' => $this->validarCrear($accion),
            'elegir_opcion' => $this->validarElegir($accion),
            'editar_opcion_menu' => $this->validarEditarOpcion($accion),
            'cambiar_categorias_toma' => $this->validarCategorias($accion),
            'editar_menu_con_comentario' => $this->validarConTexto($accion, 'editar_menu_con_comentario', 'comentario'),
            'intercambiar_menu' => $this->validarConTexto($accion, 'intercambiar_menu', 'comido'),
            default => $this->validarSimple($tipo, $accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        if ($normalizada['tipo'] === 'activar_menu') {
            return $normalizada['sustituye'];
        }

        return in_array($normalizada['tipo'], ['cancelar_generacion', 'editar_menu_con_comentario', 'descartar_cambios_menu', 'borrar_menu'], true);
    }

    private function menu(mixed $id): Menu|Rechazo
    {
        $menu = $this->v->porId(Menu::class, $id, 'menu_id', 'un menú');

        return $menu instanceof Menu ? $menu->loadMissing('hogar') : $menu;
    }

    /** El menu tiene contenido y no hay un trabajo en curso sobre el. */
    private function reposa(Menu $menu): ?string
    {
        if ($menu->contenido === null) {
            return "El menú «{$menu->nombre}» todavía no está generado.";
        }

        return $menu->estado->enCurso() ? "El menú «{$menu->nombre}» tiene un trabajo en curso." : null;
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrear(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        $inicio = $this->v->fechaDeMenu($a['fecha_inicio'] ?? null, 'fecha_inicio');
        $generar = $a['generar'] ?? false;
        if (($motivo = $this->v->motivo($hogar, $inicio)) !== null) {
            return $motivo;
        }
        if (! is_bool($generar)) {
            return 'generar tiene que ser true o false.';
        }
        $nombre = array_key_exists('nombre', $a)
            ? $this->v->texto($a['nombre'], 'nombre', 1, 255)
            : 'Menú del '.$inicio->locale('es')->isoFormat('D [de] MMMM');
        if (($motivo = $this->v->motivo($nombre)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'crear_menu', 'hogar_id' => $hogar->id, 'inicio' => $inicio->toDateString(), 'nombre' => $nombre, 'generar' => $generar,
            'quiero' => "Crear el menú «{$nombre}» para «{$hogar->nombre}» desde el {$inicio->toDateString()}".($generar ? ' y generarlo' : '')];
    }

    /** @param  array<string, mixed>  $a */
    private function validarSimple(string $tipo, array $a): array|string
    {
        $menu = $this->menu($a['menu_id'] ?? null);
        if (($motivo = $this->v->motivo($menu)) !== null) {
            return $motivo;
        }
        $base = ['tipo' => $tipo, 'menu_id' => $menu->id, 'nombre' => $menu->nombre];
        switch ($tipo) {
            case 'generar_menu':
                if (! in_array($menu->estado, [EstadoMenu::Borrador, EstadoMenu::Fallido], true)) {
                    return "El menú «{$menu->nombre}» no está en borrador ni ha fallado: solo se genera uno así.";
                }

                return $base + ['quiero' => "Generar el menú «{$menu->nombre}» (tarda unos minutos)"];

            case 'reintentar_generacion':
                return $menu->estado === EstadoMenu::EnEspera
                    ? $base + ['quiero' => "Reintentar ahora la generación del menú «{$menu->nombre}»"]
                    : "El menú «{$menu->nombre}» no está esperando a que se renueve el límite de uso.";

            case 'cancelar_generacion':
                $forzar = $a['forzar'] ?? false;
                if (! is_bool($forzar)) {
                    return 'forzar tiene que ser true o false.';
                }
                if (! $menu->estado->enCurso()) {
                    return "El menú «{$menu->nombre}» no tiene ninguna generación en curso.";
                }
                if ($forzar && $menu->estado !== EstadoMenu::EnMarcha) {
                    return "Solo se fuerza la parada de un menú en marcha; «{$menu->nombre}» está {$menu->estado->getLabel()}.";
                }

                return $base + ['forzar' => $forzar, 'quiero' => ($forzar ? 'Forzar la parada de' : 'Detener').' la generación del menú «'.$menu->nombre.'»'];

            case 'activar_menu':
                if (($motivo = $this->reposa($menu)) !== null) {
                    return $motivo;
                }
                if ($menu->hogar?->menu_id === $menu->id) {
                    return "El menú «{$menu->nombre}» ya es el activo.";
                }
                $sustituye = $menu->hogar?->menu_id !== null || ! empty($menu->hogar?->cambios);

                return $base + ['sustituye' => $sustituye, 'quiero' => "Activar el menú «{$menu->nombre}» como el que sigue el hogar"
                    .($sustituye ? ' (sustituye al actual y se descartan sus cambios por intercambio)' : '')];

            case 'descartar_cambios_menu':
                if ($menu->hogar?->menu_id !== $menu->id || $menu->tomasCambiadas() === []) {
                    return "El menú «{$menu->nombre}» no tiene cambios por intercambio que descartar.";
                }
                if ($menu->estado->enCurso()) {
                    return "El menú «{$menu->nombre}» tiene un trabajo en curso.";
                }

                return $base + ['quiero' => "Descartar los cambios por intercambio del menú «{$menu->nombre}» (vuelve a como se generó)"];

            default:   // borrar_menu
                if ($menu->estado->enCurso()) {
                    return "El menú «{$menu->nombre}» se está generando: detén la generación antes de borrarlo.";
                }

                return $base + ['quiero' => "Borrar el menú «{$menu->nombre}»"];
        }
    }

    /**
     * editar_menu_con_comentario (campo comentario) e intercambiar_menu (campo comido): un texto para el agente de menus.
     *
     * @param  array<string, mixed>  $a
     */
    private function validarConTexto(array $a, string $tipo, string $campo): array|string
    {
        $menu = $this->menu($a['menu_id'] ?? null);
        $texto = $this->v->texto($a[$campo] ?? null, $campo, 3, 2000);
        if (($motivo = $this->v->motivo($menu, $texto)) !== null) {
            return $motivo;
        }
        if (($motivo = $this->reposa($menu)) !== null) {
            return $motivo;
        }
        if ($tipo === 'intercambiar_menu' && $menu->hogar?->menu_id !== $menu->id) {
            return "Solo se intercambian tomas del menú activo del hogar; «{$menu->nombre}» no lo es.";
        }
        $corto = mb_strlen($texto) > 120 ? mb_substr($texto, 0, 120).'…' : $texto;

        return ['tipo' => $tipo, 'menu_id' => $menu->id, 'nombre' => $menu->nombre, 'texto' => $texto,
            'quiero' => $tipo === 'intercambiar_menu'
                ? "Reajustar las tomas del menú «{$menu->nombre}» según lo comido: «{$corto}»"
                : "Rehacer el menú «{$menu->nombre}» entero con el comentario: «{$corto}»"];
    }

    /**
     * La toma (fecha y momento) de un menu, comprobada contra el contenido guardado.
     *
     * @param  array<string, mixed>  $a
     * @return array{0: Menu, 1: string, 2: string, 3: int}|string menu, fecha, momento, numero de opciones; o el motivo
     */
    private function toma(array $a): array|string
    {
        $menu = $this->menu($a['menu_id'] ?? null);
        $fecha = $this->v->fechaReal($a['fecha'] ?? null);
        $momento = $a['momento'] ?? null;
        if (($motivo = $this->v->motivo($menu, $fecha)) !== null) {
            return $motivo;
        }
        if (! is_string($momento) || ! in_array($momento, ['comida', 'cena'], true)) {
            return 'momento tiene que ser comida o cena.';
        }
        if (($motivo = $this->reposa($menu)) !== null) {
            return $motivo;
        }
        $opciones = count($menu->conCambios(false)->opcionesDe($fecha->toDateString(), $momento));
        $menu->conCambios(true);
        if ($opciones === 0) {
            return "No hay toma de {$momento} el {$fecha->toDateString()} en «{$menu->nombre}» (o el hogar no cocina en ese momento).";
        }

        return [$menu, $fecha->toDateString(), $momento, $opciones];
    }

    /** @param  array<string, mixed>  $a */
    private function validarElegir(array $a): array|string
    {
        $toma = $this->toma($a);
        if (is_string($toma)) {
            return $toma;
        }
        [$menu, $fecha, $momento] = $toma;
        $total = count($menu->opcionesDe($fecha, $momento));
        $opcion = $this->v->entero($a['opcion'] ?? null, 'opcion', 0, max(0, $total - 1));
        if (($motivo = $this->v->motivo($opcion)) !== null) {
            return $motivo;
        }
        $titulo = (string) ($menu->opcionesDe($fecha, $momento)[$opcion]['titulo'] ?? '');

        return ['tipo' => 'elegir_opcion', 'menu_id' => $menu->id, 'fecha' => $fecha, 'momento' => $momento, 'opcion' => $opcion,
            'quiero' => "Elegir para la {$momento} del {$fecha}: «{$titulo}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarOpcion(array $a): array|string
    {
        $toma = $this->toma($a);
        if (is_string($toma)) {
            return $toma;
        }
        [$menu, $fecha, $momento, $total] = $toma;
        $opcion = $this->v->entero($a['opcion'] ?? null, 'opcion', 0, $total - 1);
        if (($motivo = $this->v->motivo($opcion)) !== null) {
            return $motivo;
        }
        $v = $this->v;
        $minutos = $menu->minutosDelHogar($fecha, $momento);
        $validadores = [
            'titulo' => fn ($x) => $v->texto($x, 'titulo', 3, 200),
            'descripcion' => fn ($x) => $v->texto($x, 'descripcion', 1, 1000),
            'nota' => fn ($x) => $v->texto($x, 'nota', 1, 1000),
            'pasos' => fn ($x) => $this->pasos($x),
            'duracion_previa_min' => fn ($x) => $v->entero($x, 'duracion_previa_min', 0, 1440),
            'duracion_momento_min' => fn ($x) => $v->entero($x, 'duracion_momento_min', 0, $minutos ?? 1440),
        ];
        $cambios = [];
        foreach ($validadores as $campo => $valida) {
            if (array_key_exists($campo, $a)) {
                $valor = $valida($a[$campo]);
                if (($motivo = $v->motivo($valor)) !== null) {
                    return $motivo;
                }
                $cambios[$campo] = $valor;
            }
        }
        if ($cambios === []) {
            return 'La edición de la opción no cambia nada.';
        }
        $titulo = (string) ($menu->conCambios(false)->opcionesDe($fecha, $momento)[$opcion]['titulo'] ?? '');
        $menu->conCambios(true);

        return ['tipo' => 'editar_opcion_menu', 'menu_id' => $menu->id, 'fecha' => $fecha, 'momento' => $momento, 'opcion' => $opcion, 'cambios' => $cambios,
            'quiero' => "Editar «{$titulo}» ({$momento} del {$fecha}): ".implode(', ', array_keys($cambios))];
    }

    /** @return list<string>|Rechazo */
    private function pasos(mixed $valor): array|Rechazo
    {
        if (! is_array($valor) || ! array_is_list($valor) || $valor === [] || count($valor) > 40) {
            return $this->v->rechazo('pasos tiene que ser una lista de 1 a 40 textos.');
        }
        $pasos = [];
        foreach ($valor as $paso) {
            $t = $this->v->texto($paso, 'cada paso', 1, 500);
            if (($motivo = $this->v->motivo($t)) !== null) {
                return $this->v->rechazo($motivo);
            }
            $pasos[] = $t;
        }

        return $pasos;
    }

    /** @param  array<string, mixed>  $a */
    private function validarCategorias(array $a): array|string
    {
        $toma = $this->toma($a);
        if (is_string($toma)) {
            return $toma;
        }
        [$menu, $fecha, $momento] = $toma;
        $categorias = $this->v->categorias($a['categorias'] ?? null, true);
        if (($motivo = $this->v->motivo($categorias)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'cambiar_categorias_toma', 'menu_id' => $menu->id, 'fecha' => $fecha, 'momento' => $momento, 'categorias' => $categorias,
            'quiero' => "Cambiar las categorías de la {$momento} del {$fecha} a: ".implode(', ', $categorias)];
    }

    public function aplicar(array $a): string
    {
        switch ($a['tipo']) {
            case 'crear_menu':
                $menu = GestionMenus::crear($a['nombre'], Hogar::query()->findOrFail($a['hogar_id']), CarbonImmutable::parse($a['inicio']));
                if ($a['generar']) {
                    GestionMenus::generar($menu);

                    return "Creado el menú «{$a['nombre']}» y puesto en cola para generarlo (tarda unos minutos).";
                }

                return "Creado el menú «{$a['nombre']}» en borrador.";

            case 'generar_menu':
                GestionMenus::generar(Menu::query()->findOrFail($a['menu_id']));

                return "Generación del menú «{$a['nombre']}» en cola; tarda unos minutos.";

            case 'reintentar_generacion':
                GestionMenus::reintentar(Menu::query()->findOrFail($a['menu_id']));

                return "Reintentando la generación del menú «{$a['nombre']}».";

            case 'cancelar_generacion':
                $menu = Menu::query()->findOrFail($a['menu_id']);
                if ($a['forzar']) {
                    GestionMenus::forzarParada($menu);

                    return "Parada forzada la generación del menú «{$a['nombre']}».";
                }

                return GestionMenus::detener($menu)
                    ? "La generación del menú «{$a['nombre']}» se detendrá al acabar la ejecución en marcha."
                    : "Detenida la generación del menú «{$a['nombre']}».";

            case 'activar_menu':
                $menu = Menu::query()->with('hogar')->findOrFail($a['menu_id']);
                $menu->hogar->activar($menu);

                return "El menú «{$a['nombre']}» es ahora el activo.";

            case 'elegir_opcion':
                Menu::query()->findOrFail($a['menu_id'])->elegirOpcion($a['fecha'], $a['momento'], $a['opcion']);

                return "Elegida la opción ".($a['opcion'] + 1)." de la {$a['momento']} del {$a['fecha']}.";

            case 'editar_menu_con_comentario':
                Menu::query()->findOrFail($a['menu_id'])->pedirEdicion($a['texto']);

                return "El menú «{$a['nombre']}» se está rehaciendo con tu comentario; tarda unos minutos.";

            case 'intercambiar_menu':
                $lanzado = Menu::query()->findOrFail($a['menu_id'])->pedirIntercambio($a['texto']);

                return $lanzado ? "Reajustando las tomas del menú «{$a['nombre']}»; tarda unos minutos." : 'Anotado: se reajustará al acabar el intercambio que está en marcha.';

            case 'descartar_cambios_menu':
                Menu::query()->with('hogar')->findOrFail($a['menu_id'])->hogar->descartarCambios();

                return "Descartados los cambios por intercambio del menú «{$a['nombre']}».";

            case 'editar_opcion_menu':
                EditaMenu::editarOpcion(Menu::query()->with('hogar')->findOrFail($a['menu_id']), $a['fecha'], $a['momento'], $a['opcion'], $a['cambios']);

                return "Editada la opción ".($a['opcion'] + 1)." de la {$a['momento']} del {$a['fecha']} (".implode(', ', array_keys($a['cambios'])).').';

            case 'cambiar_categorias_toma':
                EditaMenu::cambiarCategorias(Menu::query()->with('hogar')->findOrFail($a['menu_id']), $a['fecha'], $a['momento'], $a['categorias']);

                return "Categorías de la {$a['momento']} del {$a['fecha']} cambiadas a: ".implode(', ', $a['categorias']).'.';

            default:
                Menu::query()->findOrFail($a['menu_id'])->delete();

                return "Borrado el menú «{$a['nombre']}».";
        }
    }
}
