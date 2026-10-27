<?php

namespace App\Models\Menu;

use App\Models\Hogar;
use App\Services\Menu\RegistroConsumo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

trait ImportaEntrega
{
    /**
     * Guarda la entrega del agente según la tarea del menú. Si no cumple el contrato (ver ValidaEntrega) lanza una excepción con los errores y no guarda nada.
     * El consumo por respuesta (`consumo.filas`) va al libro `consumos_menu` en la misma transacción; en `contenido` solo quedan los totales.
     *  - generar: guarda el menú; si el hogar no tiene menú activo, este pasa a serlo.
     *  - editar: guarda el menú nuevo. Se conservan las elecciones que siguen siendo válidas y se descartan los cambios del hogar.
     *  - intercambiar: no toca `contenido`; fusiona las tomas de `cambios` en `hogares.cambios`.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws InvalidArgumentException
     */
    public function importarEntrega(array $datos): void
    {
        $errores = $this->validarEntrega($datos);
        if ($errores !== []) {
            $resto = count($errores) - 8;
            throw new InvalidArgumentException('La entrega del agente no es válida: '.implode(' ', array_slice($errores, 0, 8)).($resto > 0 ? " (y {$resto} errores más)" : ''));
        }
        $filas = $datos['consumo']['filas'] ?? [];
        unset($datos['consumo']['filas']);

        DB::transaction(function () use ($datos, $filas): void {
            // El hogar se carga de nuevo y bloqueado: durante la tanda (minutos) pudo activarse otro menú o cambiar `cambios`.
            $this->setRelation('hogar', Hogar::query()->lockForUpdate()->find($this->hogar_id));
            if ($this->tarea === 'intercambiar' && $this->hogar?->menu_id !== $this->id) {
                throw new InvalidArgumentException('El menú ya no es el activo del hogar: el intercambio no se aplica.');
            }
            match ($this->tarea) {
                'intercambiar' => $this->guardarCambios($datos['cambios']),
                'editar' => $this->cambiarContenido($datos),
                default => $this->update(['contenido' => $datos, 'selecciones' => null, 'huella' => hash('sha256', json_encode($datos))]),
            };
            RegistroConsumo::registrar($this, is_array($filas) ? $filas : []);
            // El primer menú generado de un hogar pasa a ser el activo (también si el activo se borró); después se activa a mano.
            if ($this->tarea === 'generar' && $this->hogar && $this->hogar->menuActivo()->doesntExist()) {
                $this->hogar->activar($this);
            }
        });
    }

    /** Pone `$contenido` como el menú y arrastra lo que depende de él: elecciones y cambios del hogar. */
    private function cambiarContenido(array $contenido): void
    {
        $this->contenido = $contenido;
        // Una elección sigue valiendo solo si ese índice existe en la toma nueva: se comprueba contra el menú nuevo, sin los cambios del anterior.
        $selecciones = $this->conCambios(false)->selecciones ?? [];
        // Las elecciones en tomas cambiadas por intercambio eran índices de las opciones cambiadas, que con la edición se descartan.
        $cambiadas = $this->hogar?->menu_id === $this->id ? array_keys($this->hogar->cambios ?? []) : [];
        $selecciones = collect(Arr::except($selecciones, $cambiadas))->filter(fn (mixed $v, string $clave): bool => $this->eleccionValida($clave, $v))->all();
        $this->conCambios(true);
        $this->update(['contenido' => $contenido, 'huella' => hash('sha256', json_encode($contenido)), 'selecciones' => $selecciones ?: null]);
        // Los cambios del hogar eran sobre el menú anterior.
        if ($this->hogar?->menu_id === $this->id) {
            $this->hogar->descartarCambios();
        }
    }

    /**
     * Fusiona en `hogares.cambios` las tomas del intercambio (las nuevas pisan a las anteriores de la misma toma) y olvida la
     * elección guardada en ellas, porque sus opciones ya no son las mismas.
     *
     * @param  array<string, array<string, mixed>>  $cambios
     */
    private function guardarCambios(array $cambios): void
    {
        $nuevos = array_map(fn (array $toma): array => Arr::only($toma, ['categorias', 'opciones']), $cambios);
        $this->hogar->forceFill(['cambios' => array_merge($this->hogar->cambios ?? [], $nuevos) ?: null])->save();
        $selecciones = Arr::except($this->selecciones ?? [], array_keys($nuevos));
        $this->update(['selecciones' => $selecciones ?: null]);
    }
}
