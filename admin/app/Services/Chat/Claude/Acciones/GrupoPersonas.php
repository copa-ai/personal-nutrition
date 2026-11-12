<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Enums\DiaSemana;
use App\Models\DisponibilidadDia;
use App\Models\Hogar;
use App\Models\Perfil;
use App\Services\Chat\Claude\Rechazo;
use App\Services\Chat\Claude\Validadores;

/**
 * Hogares, perfiles y tiempos de cocinado por dia de la semana (lo que se edita en Hogares y Perfiles del panel).
 *   crear_hogar {nombre}                         renombrar_hogar {hogar, nombre}        borrar_hogar {hogar} (confirmacion)
 *   crear_perfil {hogar, nombre, activo?, objetivo?, kcal_diarias?, proteina_pct?, carbohidratos_pct?, grasa_pct?, restricciones?, notas?}
 *   editar_perfil {perfil, nombre?, activo?, objetivo?, kcal_diarias?, proteina_pct?, carbohidratos_pct?, grasa_pct?, restricciones?, notas?}
 *   borrar_perfil {perfil} (confirmacion)
 *   fijar_tiempos {hogar, dia_semana: 1-7, minutos_desayuno?, minutos_almuerzo?, minutos_comida?, minutos_merienda?, minutos_cena?}
 *   borrar_tiempos {hogar, dia_semana} (confirmacion)
 * `hogar` y `perfil` son un id (o un nombre exacto si es unico). Un minutos_* a null es «no se cocina» (comida, cena) o «sin limite».
 */
class GrupoPersonas implements GrupoAcciones
{
    private const CAMPOS_PERFIL = ['nombre', 'activo', 'objetivo', 'kcal_diarias', 'proteina_pct', 'carbohidratos_pct', 'grasa_pct', 'restricciones', 'notas'];

    private const MINUTOS = ['minutos_desayuno', 'minutos_almuerzo', 'minutos_comida', 'minutos_merienda', 'minutos_cena'];

    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'crear_hogar' => ['tipo', 'nombre'],
            'renombrar_hogar' => ['tipo', 'hogar', 'nombre'],
            'borrar_hogar' => ['tipo', 'hogar'],
            'crear_perfil' => ['tipo', 'hogar', ...self::CAMPOS_PERFIL],
            'editar_perfil' => ['tipo', 'perfil', ...self::CAMPOS_PERFIL],
            'borrar_perfil' => ['tipo', 'perfil'],
            'fijar_tiempos' => ['tipo', 'hogar', 'dia_semana', ...self::MINUTOS],
            'borrar_tiempos' => ['tipo', 'hogar', 'dia_semana'],
        ];
    }

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'crear_hogar' => $this->validarCrearHogar($accion),
            'renombrar_hogar' => $this->validarRenombrarHogar($accion),
            'borrar_hogar' => $this->validarBorrarHogar($accion),
            'crear_perfil' => $this->validarCrearPerfil($accion),
            'editar_perfil' => $this->validarEditarPerfil($accion),
            'borrar_perfil' => $this->validarBorrarPerfil($accion),
            'fijar_tiempos' => $this->validarFijarTiempos($accion),
            default => $this->validarBorrarTiempos($accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        return in_array($normalizada['tipo'], ['borrar_hogar', 'borrar_perfil', 'borrar_tiempos'], true);
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrearHogar(array $a): array|string
    {
        $nombre = $this->v->texto($a['nombre'] ?? null, 'nombre', 1, 255);
        if (($motivo = $this->v->motivo($nombre)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'crear_hogar', 'nombre' => $nombre, 'quiero' => "Crear el hogar «{$nombre}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarRenombrarHogar(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        $nombre = $this->v->texto($a['nombre'] ?? null, 'nombre', 1, 255);
        if (($motivo = $this->v->motivo($hogar, $nombre)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'renombrar_hogar', 'hogar_id' => $hogar->id, 'nombre' => $nombre, 'quiero' => "Renombrar el hogar «{$hogar->nombre}» a «{$nombre}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarHogar(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        if (($motivo = $this->v->motivo($hogar)) !== null) {
            return $motivo;
        }
        $perfiles = $hogar->perfiles()->count();
        $menus = $hogar->menus()->count();

        return ['tipo' => 'borrar_hogar', 'hogar_id' => $hogar->id, 'nombre' => $hogar->nombre,
            'quiero' => "Borrar el hogar «{$hogar->nombre}» con sus {$perfiles} perfiles, {$menus} menús, existencias y tiempos"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrearPerfil(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        $campos = $this->camposPerfil($a, true);
        if (($motivo = $this->v->motivo($hogar)) !== null) {
            return $motivo;
        }
        if (is_string($campos)) {
            return $campos;
        }
        $campos += ['activo' => true];
        if (is_string($macros = $this->macros($campos))) {
            return $macros;
        }

        return ['tipo' => 'crear_perfil', 'hogar_id' => $hogar->id, 'campos' => $campos, 'quiero' => "Crear el perfil «{$campos['nombre']}» en el hogar «{$hogar->nombre}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarPerfil(array $a): array|string
    {
        $perfil = $this->v->perfilCualquiera($a['perfil'] ?? null);
        if (($motivo = $this->v->motivo($perfil)) !== null) {
            return $motivo;
        }
        $campos = $this->camposPerfil($a, false);
        if (is_string($campos)) {
            return $campos;
        }
        if ($campos === []) {
            return 'La edición del perfil no cambia nada.';
        }
        $resultante = $campos + [
            'proteina_pct' => $perfil->proteina_pct, 'carbohidratos_pct' => $perfil->carbohidratos_pct, 'grasa_pct' => $perfil->grasa_pct,
        ];
        if (array_intersect_key($campos, array_flip(['proteina_pct', 'carbohidratos_pct', 'grasa_pct'])) !== [] && is_string($macros = $this->macros($resultante))) {
            return $macros;
        }

        return ['tipo' => 'editar_perfil', 'perfil_id' => $perfil->id, 'campos' => $campos, 'nombre' => $perfil->nombre,
            'quiero' => "Editar el perfil «{$perfil->nombre}» (".implode(', ', array_keys($campos)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarPerfil(array $a): array|string
    {
        $perfil = $this->v->perfilCualquiera($a['perfil'] ?? null);
        if (($motivo = $this->v->motivo($perfil)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'borrar_perfil', 'perfil_id' => $perfil->id, 'nombre' => $perfil->nombre,
            'quiero' => "Borrar el perfil «{$perfil->nombre}» con sus gustos y registros de comida"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarFijarTiempos(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        $dia = $this->v->enum(DiaSemana::class, $a['dia_semana'] ?? null, 'dia_semana');
        $minutos = [];
        foreach (self::MINUTOS as $campo) {
            if (array_key_exists($campo, $a)) {
                $valor = $this->v->entero($a[$campo], $campo, 0, 600, true);
                if (($motivo = $this->v->motivo($valor)) !== null) {
                    return $motivo;
                }
                $minutos[$campo] = $valor;
            }
        }
        if (($motivo = $this->v->motivo($hogar, $dia)) !== null) {
            return $motivo;
        }
        if ($minutos === []) {
            return 'fijar_tiempos necesita al menos un minutos_*.';
        }

        return ['tipo' => 'fijar_tiempos', 'hogar_id' => $hogar->id, 'dia' => $dia->value, 'minutos' => $minutos,
            'quiero' => "Fijar los tiempos de cocinado del {$dia->getLabel()} en «{$hogar->nombre}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarTiempos(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        $dia = $this->v->enum(DiaSemana::class, $a['dia_semana'] ?? null, 'dia_semana');
        if (($motivo = $this->v->motivo($hogar, $dia)) !== null) {
            return $motivo;
        }
        if (! DisponibilidadDia::query()->where('hogar_id', $hogar->id)->where('dia_semana', $dia->value)->exists()) {
            return "El hogar «{$hogar->nombre}» no tiene tiempos para el {$dia->getLabel()}.";
        }

        return ['tipo' => 'borrar_tiempos', 'hogar_id' => $hogar->id, 'dia' => $dia->value, 'quiero' => "Borrar los tiempos de cocinado del {$dia->getLabel()} en «{$hogar->nombre}»"];
    }

    /**
     * Los campos de un perfil que vienen en la accion, validados. En la creacion el nombre es obligatorio.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|string
     */
    private function camposPerfil(array $a, bool $creando): array|string
    {
        $v = $this->v;
        $validadores = [
            'nombre' => fn ($x) => $v->texto($x, 'nombre', 1, 255),
            'activo' => fn ($x) => is_bool($x) ? $x : $v->rechazo('activo tiene que ser true o false.'),
            'objetivo' => fn ($x) => $x === null ? null : $v->texto($x, 'objetivo', 1, 2000),
            'kcal_diarias' => fn ($x) => $v->entero($x, 'kcal_diarias', 500, 10000, true),
            'proteina_pct' => fn ($x) => $x === null ? null : $v->numero($x, 'proteina_pct', 0, 100),
            'carbohidratos_pct' => fn ($x) => $x === null ? null : $v->numero($x, 'carbohidratos_pct', 0, 100),
            'grasa_pct' => fn ($x) => $x === null ? null : $v->numero($x, 'grasa_pct', 0, 100),
            'restricciones' => fn ($x) => $this->restricciones($x),
            'notas' => fn ($x) => $x === null ? null : $v->texto($x, 'notas', 1, 2000),
        ];
        if ($creando && ! array_key_exists('nombre', $a)) {
            return 'Falta el nombre del perfil.';
        }
        $campos = [];
        foreach ($validadores as $campo => $valida) {
            if (array_key_exists($campo, $a)) {
                $valor = $valida($a[$campo]);
                if (($motivo = $v->motivo($valor)) !== null) {
                    return $motivo;
                }
                $campos[$campo] = $valor;
            }
        }

        return $campos;
    }

    /** @return list<string>|Rechazo */
    private function restricciones(mixed $valor): array|Rechazo
    {
        if (! is_array($valor) || ! array_is_list($valor) || count($valor) > 30) {
            return $this->v->rechazo('restricciones tiene que ser una lista de hasta 30 textos.');
        }
        $limpias = [];
        foreach ($valor as $texto) {
            $t = $this->v->texto($texto, 'cada restricción', 1, 120);
            if (($motivo = $this->v->motivo($t)) !== null) {
                return $this->v->rechazo($motivo);
            }
            $limpias[] = $t;
        }

        return array_values(array_unique($limpias));
    }

    /**
     * Como en el panel: si hay algun porcentaje de macros, los tres tienen que dar 100.
     *
     * @param  array<string, mixed>  $campos
     */
    private function macros(array $campos): ?string
    {
        $macros = [$campos['proteina_pct'] ?? null, $campos['carbohidratos_pct'] ?? null, $campos['grasa_pct'] ?? null];
        if (count(array_filter($macros, fn (mixed $m): bool => $m !== null)) > 0 && abs(array_sum($macros) - 100) > 0.001) {
            return 'Los tres porcentajes de macros deben sumar 100 (ahora suman '.array_sum($macros).').';
        }

        return null;
    }

    public function aplicar(array $a): string
    {
        switch ($a['tipo']) {
            case 'crear_hogar':
                (new Hogar)->fill(['nombre' => $a['nombre']])->save();

                return "Creado el hogar «{$a['nombre']}».";

            case 'renombrar_hogar':
                Hogar::query()->findOrFail($a['hogar_id'])->fill(['nombre' => $a['nombre']])->save();

                return "Hogar renombrado a «{$a['nombre']}».";

            case 'borrar_hogar':
                Hogar::query()->findOrFail($a['hogar_id'])->delete();

                return "Borrado el hogar «{$a['nombre']}».";

            case 'crear_perfil':
                (new Perfil)->fill(['hogar_id' => $a['hogar_id']] + $a['campos'])->save();

                return "Creado el perfil «{$a['campos']['nombre']}».";

            case 'editar_perfil':
                Perfil::query()->findOrFail($a['perfil_id'])->fill($a['campos'])->save();

                return "Editado el perfil «{$a['nombre']}» (".implode(', ', array_keys($a['campos'])).').';

            case 'borrar_perfil':
                Perfil::query()->findOrFail($a['perfil_id'])->delete();

                return "Borrado el perfil «{$a['nombre']}».";

            case 'fijar_tiempos':
                $dia = DisponibilidadDia::query()->where('hogar_id', $a['hogar_id'])->where('dia_semana', $a['dia'])->first() ?? new DisponibilidadDia;
                $dia->fill(['dia_semana' => $a['dia']] + $a['minutos'])->forceFill(['hogar_id' => $a['hogar_id']])->save();

                return 'Tiempos de cocinado del '.DiaSemana::from($a['dia'])->getLabel().' guardados.';

            default:
                DisponibilidadDia::query()->where('hogar_id', $a['hogar_id'])->where('dia_semana', $a['dia'])->delete();

                return 'Borrados los tiempos de cocinado del '.DiaSemana::from($a['dia'])->getLabel().'.';
        }
    }
}
