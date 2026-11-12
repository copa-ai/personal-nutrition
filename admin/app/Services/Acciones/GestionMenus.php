<?php

namespace App\Services\Acciones;

use App\Enums\EstadoMenu;
use App\Jobs\GenerarMenu;
use App\Models\Hogar;
use App\Models\Menu;
use App\Services\Claude\EjecutorClaude;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Operaciones sobre un menú que comparten el panel (AccionesEjecucion, ViewMenu) y el chat (AccionesChat): crear, generar, reintentar,
 * detener y forzar la parada. Editar con comentario, intercambiar, elegir opción, activar y descartar cambios ya viven en Menu y Hogar.
 * Ciclo: borrador -> en_cola -> en_marcha <-> en_espera -> terminado | fallido.
 */
final class GestionMenus
{
    /** Crea un menú en borrador para un hogar; la semana empieza en `$inicio`. */
    public static function crear(string $nombre, Hogar $hogar, CarbonInterface $inicio): Menu
    {
        $menu = new Menu;
        $menu->forceFill(['nombre' => $nombre, 'hogar_id' => $hogar->id, 'fecha_inicio' => $inicio->toDateString()])->save();

        return $menu;
    }

    /**
     * Pone en cola la generación; lo recoge `php artisan queue:work`. Antes comprueba que el encargo se puede construir (hay perfiles
     * activos y tiempos de cocinado): si falta algo, no gasta una ejecución del agente.
     *
     * @throws InvalidArgumentException si el menú no está en borrador o fallido, o falta información
     */
    public static function generar(Menu $menu): void
    {
        if (! in_array($menu->estado, [EstadoMenu::Borrador, EstadoMenu::Fallido], true)) {
            throw new InvalidArgumentException('Solo se genera un menú en borrador o que haya fallado.');
        }
        $menu->construirEncargo();
        $menu->update(['estado' => EstadoMenu::EnCola, 'error' => null, 'detener_pedido' => false]);
        GenerarMenu::dispatch($menu->id);
    }

    /**
     * Tras agotarse el límite de uso del plan el menú espera a que se renueve; esto adelanta el reintento.
     *
     * @throws InvalidArgumentException si el menú no está en espera
     */
    public static function reintentar(Menu $menu): void
    {
        if ($menu->estado !== EstadoMenu::EnEspera) {
            throw new InvalidArgumentException('El menú no está esperando a que se renueve el límite de uso.');
        }
        $menu->update(['estado' => EstadoMenu::EnCola, 'proximo_ciclo_en' => null, 'detener_pedido' => false]);
        GenerarMenu::dispatch($menu->id);
    }

    /**
     * Parada ordenada. En marcha: se marca y la ejecución termina sola (devuelve true). En cola o en espera: se cierra ya (devuelve false).
     *
     * @throws InvalidArgumentException si el menú no tiene un trabajo en curso
     */
    public static function detener(Menu $menu): bool
    {
        if (! $menu->estado->enCurso()) {
            throw new InvalidArgumentException('El menú no tiene ningún trabajo en curso.');
        }
        if ($menu->estado === EstadoMenu::EnMarcha) {
            $menu->update(['detener_pedido' => true]);   // EjecutorAgente::esperar() lo respeta al acabar

            return true;
        }
        // En cola o en espera: el job pendiente ya no encuentra el menú en cola/espera y no hace nada.
        $menu->update(['estado' => self::estadoTrasParar($menu), 'terminado_en' => now(), 'proximo_ciclo_en' => null, 'error' => null]);

        return false;
    }

    /**
     * Para una ejecución colgada: cierra el estado ANTES de matar (así el job no lo pisa con «fallido») y cancela en el agente.
     *
     * @throws InvalidArgumentException si el menú no está en marcha
     */
    public static function forzarParada(Menu $menu): void
    {
        if ($menu->estado !== EstadoMenu::EnMarcha) {
            throw new InvalidArgumentException('El menú no está en marcha.');
        }
        $pid = (int) $menu->proceso_pid;
        $ejecucion = (string) $menu->ejecucion_id;
        $menu->update(['estado' => self::estadoTrasParar($menu), 'terminado_en' => now(), 'proximo_ciclo_en' => null,
            'proceso_pid' => null, 'ejecucion_id' => null, 'detener_pedido' => false, 'error' => null]);
        if ($ejecucion !== '') {
            EjecutorClaude::cancelar($ejecucion);   // agente remoto: DELETE /ejecuciones/{id}
        }
        if ($pid > 0 && function_exists('posix_kill')) {   // claude en el propio proceso (solo desarrollo)
            posix_kill($pid, 15);
        }
    }

    /** Un menú que ya tiene resultado importado vuelve a «terminado»; si no, a «borrador» para poder generarlo de nuevo. */
    private static function estadoTrasParar(Menu $menu): EstadoMenu
    {
        return $menu->contenido !== null ? EstadoMenu::Terminado : EstadoMenu::Borrador;
    }
}
