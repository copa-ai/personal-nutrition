<?php

namespace App\Filament\Support;

use App\Enums\EstadoMenu;
use App\Jobs\GenerarMenu;
use App\Models\Menu;
use App\Services\Claude\EjecutorClaude;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Acciones de control de la generación de un menú. Se usan en dos sitios:
 *   Table::recordActions([...AccionesEjecucion::todas()])   y   ViewRecord::getHeaderActions()
 * Ciclo: borrador -> (Ejecutar) en_cola -> en_marcha <-> en_espera -> terminado | fallido.
 */
final class AccionesEjecucion
{
    /** @return list<Action> */
    public static function todas(): array
    {
        return [self::ejecutar(), self::reintentar(), self::detener(), self::forzarParada()];
    }

    /**
     * Pone en cola; lo recoge `php artisan queue:work`. Antes comprueba que el encargo se puede construir (hay perfiles activos
     * y tiempos de cocinado): si falta algo, avisa aquí y no gasta una ejecución del agente.
     */
    public static function ejecutar(): Action
    {
        return Action::make('ejecutar')->label('Generar menú')->icon(Heroicon::OutlinedPlay)->color('success')
            ->visible(fn (Menu $r): bool => in_array($r->estado, [EstadoMenu::Borrador, EstadoMenu::Fallido], true))
            ->requiresConfirmation()
            ->modalDescription('Tarda unos minutos y consume parte del límite de uso del plan. Al terminar, el menú se importa solo.')
            ->action(function (Menu $r): void {
                try {
                    $r->construirEncargo();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('Falta información para generar el menú')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                $r->update(['estado' => EstadoMenu::EnCola, 'error' => null, 'detener_pedido' => false]);
                GenerarMenu::dispatch($r->id);
                Notification::make()->title('En cola')->body('Empezará en breve; el estado se actualiza solo.')->success()->send();
            });
    }

    /** Tras agotarse el límite de uso del plan el menú espera a que se renueve; esto adelanta el reintento. */
    public static function reintentar(): Action
    {
        return Action::make('reintentar')->label('Reintentar ahora')->icon(Heroicon::OutlinedForward)->color('success')
            ->visible(fn (Menu $r): bool => $r->estado === EstadoMenu::EnEspera)
            ->requiresConfirmation()
            ->modalDescription('Si el límite de uso aún no se ha renovado, la ejecución volverá a esperar.')
            ->action(function (Menu $r): void {
                $r->update(['estado' => EstadoMenu::EnCola, 'proximo_ciclo_en' => null, 'detener_pedido' => false]);
                GenerarMenu::dispatch($r->id);
                Notification::make()->title('En cola')->success()->send();
            });
    }

    /** Parada ordenada. En marcha: se marca y la ejecución termina sola. En cola o en espera: se cierra directamente. */
    public static function detener(): Action
    {
        return Action::make('detener')->label('Detener')->icon(Heroicon::OutlinedStopCircle)->color('warning')
            ->visible(fn (Menu $r): bool => $r->estado->enCurso() && ! $r->detener_pedido)
            ->requiresConfirmation()
            ->action(function (Menu $r): void {
                if ($r->estado === EstadoMenu::EnMarcha) {
                    $r->update(['detener_pedido' => true]);   // EjecutorAgente::esperar() lo respeta al acabar
                    Notification::make()->title('Se detendrá al acabar la ejecución en marcha')->success()->send();

                    return;
                }
                // En cola o en espera: el job pendiente ya no encuentra el menú en cola/espera y no hace nada.
                $r->update(['estado' => self::estadoTrasParar($r), 'terminado_en' => now(), 'proximo_ciclo_en' => null, 'error' => null]);
                Notification::make()->title('Detenido')->success()->send();
            });
    }

    /** Para una ejecución colgada: cierra el estado ANTES de matar (así el job no lo pisa con «fallido») y cancela en el agente. */
    public static function forzarParada(): Action
    {
        return Action::make('forzar_parada')->label('Forzar parada')->icon(Heroicon::OutlinedXCircle)->color('danger')->outlined()
            ->visible(fn (Menu $r): bool => $r->estado === EstadoMenu::EnMarcha)
            ->requiresConfirmation()
            ->modalDescription('Úsalo solo si la ejecución parece colgada. Se corta ahora mismo y lo que no se haya importado se pierde.')
            ->action(function (Menu $r): void {
                $pid = (int) $r->proceso_pid;
                $ejecucion = (string) $r->ejecucion_id;
                $r->update(['estado' => self::estadoTrasParar($r), 'terminado_en' => now(), 'proximo_ciclo_en' => null,
                    'proceso_pid' => null, 'ejecucion_id' => null, 'detener_pedido' => false, 'error' => null]);
                if ($ejecucion !== '') {
                    EjecutorClaude::cancelar($ejecucion);   // agente remoto: DELETE /ejecuciones/{id}
                }
                if ($pid > 0 && function_exists('posix_kill')) {   // claude en el propio proceso (solo desarrollo)
                    posix_kill($pid, 15);
                }
                Notification::make()->title('Detenido')->success()->send();
            });
    }

    /** Un menú que ya tiene resultado importado vuelve a «terminado»; si no, a «borrador» para poder generarlo de nuevo. */
    private static function estadoTrasParar(Menu $r): EstadoMenu
    {
        return $r->contenido !== null ? EstadoMenu::Terminado : EstadoMenu::Borrador;
    }
}
