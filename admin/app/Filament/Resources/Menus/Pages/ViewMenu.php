<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Enums\EstadoMenu;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Support\AccionesEjecucion;
use App\Models\Menu;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ViewMenu extends ViewRecord
{
    protected static string $resource = MenuResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->titulo();
    }

    /** Llamado desde los botones «Elegir» de la pestaña Menú: guarda qué opción se prefiere en una toma. */
    public function elegirOpcion(string $fecha, string $momento, int $indice): void
    {
        $menu = $this->getRecord();
        if ($menu->estado->enCurso()) {
            return;   // mientras se genera, el resultado puede cambiar
        }
        try {
            $menu->elegirOpcion($fecha, $momento, $indice);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('No se pudo elegir esa opción')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ...AccionesEjecucion::todas(),
            Action::make('pdf')->label('Descargar PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('primary')
                ->visible(fn (Menu $r): bool => $r->contenido !== null && ! $r->estado->enCurso())
                ->action(fn (Menu $r) => response()->streamDownload(
                    fn () => print ($r->generarPdf()),
                    $r->nombreArchivoPdf(),
                    ['Content-Type' => 'application/pdf'],
                )),
            EditAction::make()->label('Editar')->color('gray')->visible(fn (Menu $r): bool => in_array($r->estado, [EstadoMenu::Borrador, EstadoMenu::Fallido], true)),
        ];
    }
}
