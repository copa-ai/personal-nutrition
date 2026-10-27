<?php

namespace App\Models\Menu;

use App\Enums\EstadoMenu;
use App\Jobs\GenerarMenu;
use App\Models\User;
use Filament\Notifications\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * Lanza sobre un menú ya generado las otras dos tareas del agente (la columna `tarea`): editarlo con un comentario o
 * intercambiar tomas según lo que se comió. Reutilizan estado, log y consumos del propio menú.
 */
trait PideTarea
{
    /**
     * Pide al agente que rehaga el menú siguiendo un comentario («sin pescado el jueves»). Al terminar se importa el menú entero.
     *
     * @throws InvalidArgumentException si no hay comentario, el menú no está generado, está en curso o falta información
     */
    public function pedirEdicion(string $comentario): void
    {
        $this->pedirTarea('editar', $comentario);
    }

    /**
     * Pide al agente que reajuste las tomas según lo que se comió o lo que estaba programado. Solo vale para el menú activo del hogar;
     * al terminar los cambios se guardan en `hogares.cambios` y `contenido` no se toca.
     *
     * Si ya corre otro intercambio de este menú, el texto queda pendiente y se lanza al acabar ese (no se pierde).
     *
     * @return bool true si se ha lanzado ya; false si ha quedado pendiente tras el intercambio en curso
     *
     * @throws InvalidArgumentException si no hay texto, el menú no está activo, está en curso con otra tarea o falta información
     */
    public function pedirIntercambio(string $comido): bool
    {
        if ($this->tarea === 'intercambiar' && $this->estado->enCurso() && filled(trim($comido))) {
            $this->update(['instruccion_pendiente' => trim(($this->instruccion_pendiente ? $this->instruccion_pendiente."\n" : '').trim($comido))]);

            return false;
        }
        $this->pedirTarea('intercambiar', $comido);

        return true;
    }

    /** Al acabar un intercambio, lanza los que se pidieron mientras corría (todos juntos, en una sola tanda). */
    public function lanzarPendiente(): void
    {
        $pendiente = trim((string) $this->refresh()->instruccion_pendiente);
        if ($pendiente === '') {
            return;
        }
        $this->update(['instruccion_pendiente' => null]);
        try {
            $this->pedirTarea('intercambiar', $pendiente);
        } catch (InvalidArgumentException $e) {
            $this->avisarResultado('No se pudo lanzar el intercambio pendiente: '.$e->getMessage());
        }
    }

    /**
     * Avisa en la campana del panel de cómo acabó una tarea de editar o intercambiar (generar ya se sigue en pantalla). Sin `$error`
     * es que fue bien. Un fallo al avisar nunca rompe la ejecución.
     */
    public function avisarResultado(?string $error = null): void
    {
        if ($this->tarea === 'generar') {
            return;
        }
        try {
            $que = $this->tarea === 'editar' ? 'La edición del menú' : 'El intercambio de tomas';
            $aviso = Notification::make()->title($error === null ? "{$que} ha terminado" : "{$que} ha fallado")->body($error ?? $this->titulo());
            ($error === null ? $aviso->success() : $aviso->danger())->sendToDatabase(User::all());
        } catch (Throwable) {
            // el aviso es secundario
        }
    }

    private function pedirTarea(string $tarea, string $instruccion): void
    {
        $instruccion = trim($instruccion);
        if ($instruccion === '') {
            throw new InvalidArgumentException('Hace falta escribir el texto para el agente.');
        }
        if (empty($this->contenido)) {
            throw new InvalidArgumentException('El menú todavía no se ha generado.');
        }
        if ($this->estado->enCurso()) {
            throw new InvalidArgumentException('El menú tiene un trabajo en curso.');
        }

        [$tareaPrevia, $instruccionPrevia] = [$this->tarea, $this->instruccion];
        $this->tarea = $tarea;
        $this->instruccion = $instruccion;
        try {
            $this->construirEncargo();   // comprueba ya que hay datos: si no, no se gasta una ejecución
        } catch (InvalidArgumentException $e) {
            [$this->tarea, $this->instruccion] = [$tareaPrevia, $instruccionPrevia];
            throw $e;
        }

        // ciclo = 0 y fechas vacías: es una ejecución nueva (log desde cero, una sola tanda).
        $this->update(['estado' => EstadoMenu::EnCola, 'error' => null, 'detener_pedido' => false, 'ciclo' => 0,
            'iniciado_en' => null, 'terminado_en' => null, 'proximo_ciclo_en' => null]);
        GenerarMenu::dispatch($this->id);
    }

    /**
     * Cierra con error una ejecución que ha fallado. Generar deja el menú «fallido». Editar e intercambiar no: el menú sigue
     * teniendo su contenido, así que queda «terminado» con el motivo en `error` y no se puede relanzar como una generación.
     * Editar e intercambiar además avisan en la campana; un intercambio lanza los pendientes.
     */
    public function cerrarConError(string $error): void
    {
        $this->update(['estado' => $this->tarea === 'generar' ? EstadoMenu::Fallido : EstadoMenu::Terminado, 'terminado_en' => now(),
            'proceso_pid' => null, 'ejecucion_id' => null, 'detener_pedido' => false, 'error' => $error]);
        $this->avisarResultado($error);
        if ($this->tarea === 'intercambiar') {
            $this->lanzarPendiente();
        }
    }
}
