<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Enums\CategoriaComida;
use App\Models\Alimento;
use App\Models\Existencia;
use App\Services\Acciones\Despensa;
use App\Services\Chat\Claude\Rechazo;
use App\Services\Chat\Claude\Validadores;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Catalogo de alimentos y existencias (lo que se edita en Alimentos y Existencias del panel).
 *   crear_alimento {nombre, categoria, unidad}          editar_alimento {alimento, nombre?, categoria?, unidad?}
 *   borrar_alimento {alimento} (confirmacion; no si tiene existencias)
 *   anadir_existencia {hogar, alimento, cantidad, unidad?, caduca_el?}
 *   editar_existencia {existencia_id, cantidad?, unidad?, caduca_el?}       borrar_existencia {existencia_id} (confirmacion)
 *   restar_existencias {items: [{existencia_id, cantidad}, ...]} (confirmacion; hasta 50)
 * `alimento` y `hogar` son un id (o un nombre exacto). `unidad` es g, kg, ml, l o ud.
 */
class GrupoDespensa implements GrupoAcciones
{
    private const MAX_ITEMS = 50;

    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'crear_alimento' => ['tipo', 'nombre', 'categoria', 'unidad'],
            'editar_alimento' => ['tipo', 'alimento', 'nombre', 'categoria', 'unidad'],
            'borrar_alimento' => ['tipo', 'alimento'],
            'anadir_existencia' => ['tipo', 'hogar', 'alimento', 'cantidad', 'unidad', 'caduca_el'],
            'editar_existencia' => ['tipo', 'existencia_id', 'cantidad', 'unidad', 'caduca_el'],
            'borrar_existencia' => ['tipo', 'existencia_id'],
            'restar_existencias' => ['tipo', 'items'],
        ];
    }

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'crear_alimento' => $this->validarCrearAlimento($accion),
            'editar_alimento' => $this->validarEditarAlimento($accion),
            'borrar_alimento' => $this->validarBorrarAlimento($accion),
            'anadir_existencia' => $this->validarAnadirExistencia($accion),
            'editar_existencia' => $this->validarEditarExistencia($accion),
            'borrar_existencia' => $this->validarBorrarExistencia($accion),
            default => $this->validarRestar($accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        return in_array($normalizada['tipo'], ['borrar_alimento', 'borrar_existencia', 'restar_existencias'], true);
    }

    private function unidad(mixed $valor): string|Rechazo
    {
        return is_string($valor) && array_key_exists($valor, Alimento::UNIDADES) ? $valor : $this->v->rechazo('unidad tiene que ser una de: '.implode(', ', array_keys(Alimento::UNIDADES)).'.');
    }

    private function nombreLibre(string $nombre, ?string $salvo = null): ?string
    {
        $consulta = Alimento::query()->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)]);
        $salvo !== null && $consulta->where('id', '!=', $salvo);

        return $consulta->exists() ? "Ya existe un alimento llamado «{$nombre}»." : null;
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrearAlimento(array $a): array|string
    {
        $nombre = $this->v->texto($a['nombre'] ?? null, 'nombre', 1, 255);
        $categoria = $this->v->enum(CategoriaComida::class, $a['categoria'] ?? null, 'categoria');
        $unidad = $this->unidad($a['unidad'] ?? null);
        if (($motivo = $this->v->motivo($nombre, $categoria, $unidad)) !== null) {
            return $motivo;
        }
        if (($motivo = $this->nombreLibre($nombre)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'crear_alimento', 'nombre' => $nombre, 'categoria' => $categoria, 'unidad' => $unidad, 'quiero' => "Crear el alimento «{$nombre}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarAlimento(array $a): array|string
    {
        $alimento = $this->v->alimento($a['alimento'] ?? null);
        if (($motivo = $this->v->motivo($alimento)) !== null) {
            return $motivo;
        }
        $cambios = [];
        $validadores = [
            'nombre' => fn ($x) => $this->v->texto($x, 'nombre', 1, 255),
            'categoria' => fn ($x) => $this->v->enum(CategoriaComida::class, $x, 'categoria'),
            'unidad' => fn ($x) => $this->unidad($x),
        ];
        foreach ($validadores as $campo => $valida) {
            if (array_key_exists($campo, $a)) {
                $valor = $valida($a[$campo]);
                if (($motivo = $this->v->motivo($valor)) !== null) {
                    return $motivo;
                }
                $cambios[$campo] = $valor;
            }
        }
        if ($cambios === []) {
            return 'La edición del alimento no cambia nada.';
        }
        if (isset($cambios['nombre']) && ($motivo = $this->nombreLibre($cambios['nombre'], $alimento->id)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'editar_alimento', 'alimento_id' => $alimento->id, 'cambios' => $cambios, 'nombre' => $alimento->nombre,
            'quiero' => "Editar el alimento «{$alimento->nombre}» (".implode(', ', array_keys($cambios)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarAlimento(array $a): array|string
    {
        $alimento = $this->v->alimento($a['alimento'] ?? null);
        if (($motivo = $this->v->motivo($alimento)) !== null) {
            return $motivo;
        }
        if (Existencia::query()->where('alimento_id', $alimento->id)->exists()) {
            return "El alimento «{$alimento->nombre}» tiene existencias: bórralas antes.";
        }

        return ['tipo' => 'borrar_alimento', 'alimento_id' => $alimento->id, 'nombre' => $alimento->nombre, 'quiero' => "Borrar el alimento «{$alimento->nombre}» del catálogo"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarAnadirExistencia(array $a): array|string
    {
        $hogar = $this->v->hogar($a['hogar'] ?? null);
        $alimento = $this->v->alimento($a['alimento'] ?? null);
        $cantidad = $this->v->numero($a['cantidad'] ?? null, 'cantidad', 0.01, 1000000);
        $caduca = ($a['caduca_el'] ?? null) === null ? null : $this->v->fechaReal($a['caduca_el'], 'caduca_el');
        if (($motivo = $this->v->motivo($hogar, $alimento, $cantidad, $caduca)) !== null) {
            return $motivo;
        }
        $unidad = array_key_exists('unidad', $a) ? $this->unidad($a['unidad']) : $alimento->unidad;
        if (($motivo = $this->v->motivo($unidad)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'anadir_existencia', 'hogar_id' => $hogar->id, 'alimento_id' => $alimento->id, 'cantidad' => $cantidad, 'unidad' => $unidad,
            'caduca_el' => $caduca?->toDateString(), 'quiero' => "Añadir {$cantidad} {$unidad} de {$alimento->nombre} a la despensa de «{$hogar->nombre}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarExistencia(array $a): array|string
    {
        $existencia = $this->existencia($a['existencia_id'] ?? null);
        if (is_string($existencia)) {
            return $existencia;
        }
        $cambios = [];
        $validadores = [
            'cantidad' => fn ($x) => $this->v->numero($x, 'cantidad', 0, 1000000),
            'unidad' => fn ($x) => $this->unidad($x),
            'caduca_el' => fn ($x) => $x === null ? null : $this->v->fechaReal($x, 'caduca_el'),
        ];
        foreach ($validadores as $campo => $valida) {
            if (array_key_exists($campo, $a)) {
                $valor = $valida($a[$campo]);
                if (($motivo = $this->v->motivo($valor)) !== null) {
                    return $motivo;
                }
                $cambios[$campo] = $valor instanceof CarbonImmutable ? $valor->toDateString() : $valor;
            }
        }
        if ($cambios === []) {
            return 'La edición de la existencia no cambia nada.';
        }

        return ['tipo' => 'editar_existencia', 'existencia_id' => $existencia->id, 'cambios' => $cambios, 'nombre' => $existencia->alimento?->nombre ?? 'alimento',
            'quiero' => 'Editar la existencia de '.($existencia->alimento?->nombre ?? 'un alimento').' ('.implode(', ', array_keys($cambios)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarExistencia(array $a): array|string
    {
        $existencia = $this->existencia($a['existencia_id'] ?? null);
        if (is_string($existencia)) {
            return $existencia;
        }

        return ['tipo' => 'borrar_existencia', 'existencia_id' => $existencia->id, 'nombre' => $existencia->alimento?->nombre ?? 'alimento',
            'quiero' => 'Borrar de la despensa: '.($existencia->alimento?->nombre ?? 'un alimento').' ('.(float) $existencia->cantidad.' '.$existencia->unidad.')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarRestar(array $a): array|string
    {
        $items = $a['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            return 'items tiene que ser una lista de 1 a '.self::MAX_ITEMS.' elementos {existencia_id, cantidad}.';
        }
        $cantidades = [];
        $lineas = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['existencia_id', 'cantidad']) !== []) {
                return 'Cada elemento de items tiene solo existencia_id y cantidad.';
            }
            $existencia = $this->existencia($item['existencia_id'] ?? null);
            if (is_string($existencia)) {
                return $existencia;
            }
            $cantidad = $this->v->numero($item['cantidad'] ?? null, 'cantidad', 0.01, 1000000);
            if (($motivo = $this->v->motivo($cantidad)) !== null) {
                return $motivo;
            }
            if (isset($cantidades[$existencia->id])) {
                return "La existencia {$existencia->id} está repetida en items.";
            }
            $cantidades[$existencia->id] = $cantidad;
            $lineas[] = $cantidad.' '.$existencia->unidad.' de '.($existencia->alimento?->nombre ?? 'un alimento');
        }

        return ['tipo' => 'restar_existencias', 'cantidades' => $cantidades, 'quiero' => 'Restar de la despensa: '.implode('; ', $lineas)];
    }

    private function existencia(mixed $id): Existencia|string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return 'existencia_id tiene que ser el id de una existencia.';
        }

        return Existencia::query()->with('alimento')->find($id) ?? "No existe la existencia {$id}.";
    }

    public function aplicar(array $a): string
    {
        switch ($a['tipo']) {
            case 'crear_alimento':
                (new Alimento)->fill(['nombre' => $a['nombre'], 'categoria' => $a['categoria'], 'unidad' => $a['unidad']])->save();

                return "Creado el alimento «{$a['nombre']}».";

            case 'editar_alimento':
                Alimento::query()->findOrFail($a['alimento_id'])->fill($a['cambios'])->save();

                return "Editado el alimento «{$a['nombre']}» (".implode(', ', array_keys($a['cambios'])).').';

            case 'borrar_alimento':
                Alimento::query()->findOrFail($a['alimento_id'])->delete();

                return "Borrado el alimento «{$a['nombre']}».";

            case 'anadir_existencia':
                $e = new Existencia;
                $e->fill(['hogar_id' => $a['hogar_id'], 'alimento_id' => $a['alimento_id'], 'cantidad' => $a['cantidad'], 'unidad' => $a['unidad'], 'caduca_el' => $a['caduca_el']])->save();

                return "Añadido a la despensa: {$a['cantidad']} {$a['unidad']}.";

            case 'editar_existencia':
                Existencia::query()->findOrFail($a['existencia_id'])->fill($a['cambios'])->save();

                return "Editada la existencia de {$a['nombre']} (".implode(', ', array_keys($a['cambios'])).').';

            case 'borrar_existencia':
                Existencia::query()->findOrFail($a['existencia_id'])->delete();

                return "Borrada de la despensa: {$a['nombre']}.";

            default:
                $r = Despensa::restar($a['cantidades']);

                return "Restadas {$r['restadas']} existencias de la despensa".($r['agotadas'] > 0 ? " ({$r['agotadas']} se han agotado y se han quitado)." : '.');
        }
    }
}
