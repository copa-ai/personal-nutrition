<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Enums\TipoFeedback;
use App\Models\Feedback;
use App\Models\RegistroComida;
use App\Services\Chat\Claude\Validadores;
use Illuminate\Support\Facades\Schema;

/**
 * Lo comido y los gustos: registros de comida y feedback.
 *   registrar_comida {perfil, fecha, momento, descripcion, origen?, categorias?, foto_hash?}
 *   corregir_comida  {registro_id, fecha?, momento?, descripcion?, origen?, categorias?, foto_hash?}
 *   borrar_registro_comida {registro_id}                                         (pide confirmacion)
 *   anotar_feedback  {perfil, valoracion: gusta|no_gusta, elemento, nota?}
 *   editar_feedback  {feedback_id, valoracion?, elemento?, nota?}
 *   borrar_feedback  {feedback_id}                                               (pide confirmacion)
 */
class GrupoComidas implements GrupoAcciones
{
    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'registrar_comida' => ['tipo', 'perfil', 'fecha', 'momento', 'descripcion', 'origen', 'categorias', 'foto_hash'],
            'corregir_comida' => ['tipo', 'registro_id', 'fecha', 'momento', 'descripcion', 'origen', 'categorias', 'foto_hash'],
            'borrar_registro_comida' => ['tipo', 'registro_id'],
            'anotar_feedback' => ['tipo', 'perfil', 'valoracion', 'elemento', 'nota'],
            'editar_feedback' => ['tipo', 'feedback_id', 'valoracion', 'elemento', 'nota'],
            'borrar_feedback' => ['tipo', 'feedback_id'],
        ];
    }

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'registrar_comida' => $this->validarRegistrar($accion),
            'corregir_comida' => $this->validarCorregir($accion),
            'borrar_registro_comida' => $this->validarBorrarRegistro($accion),
            'anotar_feedback' => $this->validarFeedback($accion),
            'editar_feedback' => $this->validarEditarFeedback($accion),
            default => $this->validarBorrarFeedback($accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        return in_array($normalizada['tipo'], ['borrar_registro_comida', 'borrar_feedback'], true);
    }

    /** @param  array<string, mixed>  $a */
    private function validarRegistrar(array $a): array|string
    {
        $v = $this->v;
        $perfil = $v->perfil($a['perfil'] ?? null);
        $fecha = $v->fecha($a['fecha'] ?? null);
        $momento = $v->enum(MomentoComida::class, $a['momento'] ?? null);
        $descripcion = $v->descripcion($a['descripcion'] ?? null);
        $origen = array_key_exists('origen', $a) ? $v->origen($a['origen']) : OrigenComida::Casa;
        $categorias = array_key_exists('categorias', $a) ? $v->categorias($a['categorias']) : [];
        $foto = array_key_exists('foto_hash', $a) ? $v->foto($a['foto_hash']) : null;
        if (($motivo = $v->motivo($perfil, $fecha, $momento, $descripcion, $origen, $categorias, $foto)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'registrar_comida', 'perfil_id' => $perfil->id, 'perfil' => $perfil->nombre, 'fecha' => $fecha, 'momento' => $momento, 'descripcion' => $descripcion,
            'origen' => $origen, 'categorias' => $categorias, 'foto' => $foto, 'quiero' => "Registrar para {$perfil->nombre}: {$descripcion}"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarCorregir(array $a): array|string
    {
        $v = $this->v;
        $registro = $this->registro($a['registro_id'] ?? null);
        if (is_string($registro)) {
            return $registro;
        }
        $cambios = [];
        foreach (['fecha' => fn ($x) => $v->fecha($x), 'momento' => fn ($x) => $v->enum(MomentoComida::class, $x), 'descripcion' => fn ($x) => $v->descripcion($x),
            'origen' => fn ($x) => $v->origen($x), 'categorias' => fn ($x) => $v->categorias($x), 'foto_hash' => fn ($x) => $v->foto($x)] as $campo => $valida) {
            if (array_key_exists($campo, $a)) {
                $valor = $valida($a[$campo]);
                if (($motivo = $v->motivo($valor)) !== null) {
                    return $motivo;
                }
                $cambios[$campo === 'foto_hash' ? 'foto' : $campo] = $valor;
            }
        }
        if ($cambios === []) {
            return 'La corrección no cambia nada.';
        }

        return ['tipo' => 'corregir_comida', 'registro_id' => $registro->id, 'perfil' => $registro->perfil->nombre, 'cambios' => $cambios,
            'quiero' => "Corregir el registro de {$registro->perfil->nombre} (".implode(', ', array_keys($cambios)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarRegistro(array $a): array|string
    {
        $registro = $this->registro($a['registro_id'] ?? null);
        if (is_string($registro)) {
            return $registro;
        }

        return ['tipo' => 'borrar_registro_comida', 'registro_id' => $registro->id, 'perfil' => $registro->perfil->nombre,
            'quiero' => "Borrar el registro de {$registro->perfil->nombre}: {$registro->descripcion} ({$registro->fecha->toDateString()})"];
    }

    /** Un registro de comida reciente de un perfil activo, o el motivo. */
    private function registro(mixed $id): RegistroComida|string
    {
        $registro = $this->v->porId(RegistroComida::class, $id, 'registro_id', 'un registro de comida');
        if (is_object($registro) && ! $registro instanceof RegistroComida) {
            return $registro->motivo;
        }
        $registro->loadMissing('perfil');
        if (! $registro->perfil || ! $registro->perfil->activo) {
            return "No existe el registro de comida {$registro->id}.";
        }
        if ($registro->fecha < $this->v->desde()) {
            return "El registro {$registro->id} es demasiado antiguo para tocarlo desde el chat.";
        }

        return $registro;
    }

    /** @param  array<string, mixed>  $a */
    private function validarFeedback(array $a): array|string
    {
        $v = $this->v;
        $perfil = $v->perfil($a['perfil'] ?? null);
        $tipo = $v->enum(TipoFeedback::class, $a['valoracion'] ?? null, 'valoracion');
        $elemento = $v->texto($a['elemento'] ?? null, 'elemento', 2, 255);
        $nota = ($a['nota'] ?? null) === null ? null : $v->texto($a['nota'], 'nota', 1, 500);
        if (($motivo = $v->motivo($perfil, $tipo, $elemento, $nota)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'anotar_feedback', 'perfil_id' => $perfil->id, 'perfil' => $perfil->nombre, 'valoracion' => $tipo, 'elemento' => $elemento, 'nota' => $nota,
            'quiero' => "Anotar para {$perfil->nombre}: {$tipo->getLabel()} «{$elemento}»"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarFeedback(array $a): array|string
    {
        $v = $this->v;
        $feedback = $v->porId(Feedback::class, $a['feedback_id'] ?? null, 'feedback_id', 'una anotación de gustos');
        if (($motivo = $v->motivo($feedback)) !== null) {
            return $motivo;
        }
        $cambios = [];
        $validadores = [
            'valoracion' => fn ($x) => $v->enum(TipoFeedback::class, $x, 'valoracion'),
            'elemento' => fn ($x) => $v->texto($x, 'elemento', 2, 255),
            'nota' => fn ($x) => $x === null ? null : $v->texto($x, 'nota', 1, 500),
        ];
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
            return 'La edición no cambia nada.';
        }

        return ['tipo' => 'editar_feedback', 'feedback_id' => $feedback->id, 'cambios' => $cambios, 'quiero' => 'Editar la anotación de gustos «'.$feedback->elemento.'»'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarFeedback(array $a): array|string
    {
        $feedback = $this->v->porId(Feedback::class, $a['feedback_id'] ?? null, 'feedback_id', 'una anotación de gustos');
        if (($motivo = $this->v->motivo($feedback)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'borrar_feedback', 'feedback_id' => $feedback->id, 'quiero' => 'Borrar la anotación de gustos «'.$feedback->elemento.'»'];
    }

    public function aplicar(array $a): string
    {
        $columnaFoto = Schema::hasColumn('registros_comida', 'foto_hash');
        switch ($a['tipo']) {
            case 'registrar_comida':
                $r = new RegistroComida;
                $r->fill(['perfil_id' => $a['perfil_id'], 'fecha' => $a['fecha']->toDateString(), 'momento' => $a['momento'], 'origen' => $a['origen'],
                    'descripcion' => $a['descripcion'], 'categorias' => $a['categorias'] ?: null]);
                $columnaFoto && $a['foto'] !== null && $r->forceFill(['foto_hash' => $a['foto']]);
                $r->save();

                return "Registrado para {$a['perfil']}: {$a['descripcion']} ({$a['momento']->getLabel()}, {$a['fecha']->toDateString()})"
                    .($a['foto'] !== null ? ($columnaFoto ? ', con la foto.' : '. La foto se ha guardado en el chat, pero este registro aún no puede enlazarla.') : '.');

            case 'corregir_comida':
                $r = RegistroComida::query()->lockForUpdate()->findOrFail($a['registro_id']);
                $c = $a['cambios'];
                $foto = $c['foto'] ?? null;
                unset($c['foto']);
                isset($c['fecha']) && $c['fecha'] = $c['fecha']->toDateString();
                $r->fill($c);
                $columnaFoto && $foto !== null && $r->forceFill(['foto_hash' => $foto]);
                $r->save();

                return "Corregido el registro {$a['registro_id']} de {$a['perfil']} (".implode(', ', array_keys($a['cambios'])).').';

            case 'borrar_registro_comida':
                RegistroComida::query()->findOrFail($a['registro_id'])->delete();

                return "Borrado el registro de comida de {$a['perfil']}.";

            case 'anotar_feedback':
                $f = new Feedback;
                $f->fill(['tipo' => $a['valoracion'], 'elemento' => $a['elemento'], 'nota' => $a['nota']]);
                $f->forceFill(['perfil_id' => $a['perfil_id']])->save();

                return "Anotado para {$a['perfil']}: {$a['valoracion']->getLabel()} «{$a['elemento']}».";

            case 'editar_feedback':
                $f = Feedback::query()->findOrFail($a['feedback_id']);
                $f->fill($a['cambios'] + ['nota' => $f->nota])->save();

                return 'Editada la anotación de gustos «'.$f->elemento.'».';

            default:
                $f = Feedback::query()->findOrFail($a['feedback_id']);
                $f->delete();

                return 'Borrada la anotación de gustos «'.$f->elemento.'».';
        }
    }
}
