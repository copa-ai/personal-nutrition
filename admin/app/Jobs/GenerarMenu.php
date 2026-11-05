<?php

namespace App\Jobs;

use App\Enums\EstadoMenu;
use App\Models\Menu;
use App\Services\Claude\EjecutorClaude;
use App\Services\Menu\EjecutorAgente;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerarMenu implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0; // el limite lo pone AGENTE_TIMEOUT sobre el proceso

    public function __construct(public string $menuId) {}

    public function handle(EjecutorAgente $ejecutor): void
    {
        $menu = Menu::query()->find($this->menuId);
        // Un menú en espera solo sigue cuando le toca (un aviso viejo, ya superado por otro lanzamiento, no cuenta).
        $toca = $menu && ($menu->estado === EstadoMenu::EnCola
            || ($menu->estado === EstadoMenu::EnEspera && $menu->proximo_ciclo_en?->subSeconds(5)->isPast()));
        if ($toca) {
            $ejecutor->ejecutar($menu);
        }
    }

    /** Si la cola mata el job a medias (contenedor reiniciado, tiempo agotado), el menú no se queda «en marcha» para siempre. */
    public function failed(\Throwable $e): void
    {
        $ejecucion = Menu::query()->whereKey($this->menuId)->where('estado', EstadoMenu::EnMarcha)->value('ejecucion_id');
        $ejecucion && EjecutorClaude::cancelar($ejecucion);   // que el agente no siga gastando el plan con una tanda que nadie espera
        $menu = Menu::query()->whereKey($this->menuId)->where('estado', EstadoMenu::EnMarcha)->first();
        $menu?->cerrarConError('La tanda se interrumpió: '.$e->getMessage().' Puedes volver a ejecutarlo: retoma donde se quedó.');
    }
}
