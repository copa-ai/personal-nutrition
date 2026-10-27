<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Enums\EstadoMenu;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Support\AccionesEjecucion;
use App\Models\Menu;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ViewMenu extends ViewRecord
{
    protected static string $resource = MenuResource::class;

    /** Interruptor «menú vigente / por defecto»: true enseña el menú tal como lo generó el agente, sin los cambios por intercambio. */
    public bool $verPorDefecto = false;

    public function getTitle(): string
    {
        return $this->getRecord()->titulo();
    }

    /** Llamado desde los botones «Elegir» de la pestaña Menú: guarda qué opción se prefiere en una toma. */
    public function elegirOpcion(string $fecha, string $momento, int $indice): void
    {
        $menu = $this->getRecord();
        if ($menu->estado->enCurso() || $this->verPorDefecto) {
            return;   // mientras se genera, el resultado puede cambiar; y las elecciones son sobre el menú vigente
        }
        try {
            $menu->elegirOpcion($fecha, $momento, $indice);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('No se pudo elegir esa opción')->body($e->getMessage())->danger()->send();
        }
    }

    /** El menú está generado y no tiene un trabajo en curso: se puede activar, editar o cambiar lo que se sigue. */
    private function reposa(Menu $r): bool
    {
        return $r->contenido !== null && ! $r->estado->enCurso();
    }

    /** Fija este menú como el que sigue el hogar. Al cambiar de menú se pierden los cambios por intercambio del anterior. */
    private function accionActivar(): Action
    {
        return Action::make('activar')->label('Activar')->icon(Heroicon::OutlinedCheckCircle)->color('success')
            ->visible(fn (Menu $r): bool => $this->reposa($r) && $r->hogar?->menu_id !== $r->id)
            ->requiresConfirmation()
            ->modalDescription('Será el menú que sigue el hogar. Los cambios por intercambio del menú activo actual se descartan.')
            ->action(function (Menu $r): void {
                $r->hogar->activar($r);
                $this->verPorDefecto = false;
                Notification::make()->title('Menú activado')->success()->send();
            });
    }

    /** Pide al agente que rehaga el menú entero siguiendo un comentario. */
    private function accionEditarConComentario(): Action
    {
        return Action::make('editar_con_comentario')->label('Editar con comentario')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn (Menu $r): bool => $this->reposa($r))
            ->modalDescription('El agente rehace el menú con tu comentario. Tarda unos minutos y consume parte del límite de uso. Los cambios por intercambio del hogar se descartan.')
            ->schema([
                Textarea::make('comentario')->label('Qué hay que cambiar')->required()->rows(4)->maxLength(2000)
                    ->placeholder('Por ejemplo: sin pescado el jueves, y cenas más ligeras.'),
            ])
            ->action(function (Menu $r, array $data): void {
                try {
                    $r->pedirEdicion((string) $data['comentario']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('No se pudo pedir la edición')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                Notification::make()->title('En cola')->body('El agente rehará el menú; el estado se actualiza solo.')->success()->send();
            });
    }

    /** Interruptor entre el menú vigente (con las tomas cambiadas) y el por defecto. Solo tiene sentido si hay cambios. */
    private function accionVerVersion(): Action
    {
        return Action::make('ver_version')
            ->label(fn (): string => $this->verPorDefecto ? 'Ver menú vigente' : 'Ver menú por defecto')
            ->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
            ->visible(fn (Menu $r): bool => $r->tomasCambiadas() !== [])
            ->action(fn () => $this->verPorDefecto = ! $this->verPorDefecto);
    }

    /** Quita los cambios por intercambio: el menú activo vuelve a valer tal como se generó. */
    private function accionDescartarCambios(): Action
    {
        return Action::make('descartar_cambios')->label('Descartar cambios')->icon(Heroicon::OutlinedTrash)->color('danger')->outlined()
            ->visible(fn (Menu $r): bool => $r->tomasCambiadas() !== [] && ! $r->estado->enCurso())
            ->requiresConfirmation()
            ->modalDescription('Las tomas cambiadas por lo comido vuelven a ser las del menú original.')
            ->action(function (Menu $r): void {
                $r->hogar->descartarCambios();
                $this->verPorDefecto = false;
                Notification::make()->title('Cambios descartados')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            ...AccionesEjecucion::todas(),
            $this->accionActivar(),
            $this->accionEditarConComentario(),
            $this->accionVerVersion(),
            $this->accionDescartarCambios(),
            Action::make('pdf')->label('Descargar PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('primary')
                ->visible(fn (Menu $r): bool => $r->contenido !== null && ! $r->estado->enCurso())
                ->action(fn (Menu $r) => response()->streamDownload(
                    fn () => print ($r->generarPdf(! $this->verPorDefecto)),
                    $r->nombreArchivoPdf(),
                    ['Content-Type' => 'application/pdf'],
                )),
            EditAction::make()->label('Editar')->color('gray')->visible(fn (Menu $r): bool => in_array($r->estado, [EstadoMenu::Borrador, EstadoMenu::Fallido], true)),
        ];
    }
}
