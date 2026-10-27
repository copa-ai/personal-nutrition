<?php

namespace App\Filament\Resources\Menus\Tables;

use App\Enums\EstadoMenu;
use App\Filament\Support\AccionesEjecucion;
use App\Models\Menu;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Menú')->formatStateUsing(fn (Menu $r): string => $r->titulo())->searchable()->sortable(),
                TextColumn::make('hogar.nombre')->label('Hogar')->toggleable(),
                IconColumn::make('activo')->label('Activo')->boolean()->trueIcon(Heroicon::OutlinedCheckCircle)->falseIcon(Heroicon::OutlinedMinus)->falseColor('gray')
                    ->state(fn (Menu $r): bool => $r->hogar?->menu_id === $r->id)->tooltip(fn (Menu $r): string => $r->hogar?->menu_id === $r->id ? 'Es el menú que sigue el hogar' : 'No es el menú activo'),
                TextColumn::make('fecha_inicio')->label('Semana desde')->date('d/m/Y')->sortable(),
                TextColumn::make('estado')->label('Estado')->badge()->formatStateUsing(fn (Menu $r): string => $r->etiquetaEstado())
                    ->description(fn (Menu $r): ?string => $r->estado->enCurso() ? ['editar' => 'Editando', 'intercambiar' => 'Intercambiando'][$r->tarea] ?? null : null),
                TextColumn::make('iniciado_en')->label('Iniciado')->since()->placeholder('—')->sortable(),
                TextColumn::make('updated_at')->label('Actualizado')->since()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error')->label('Error')->limit(60)->tooltip(fn (Menu $r): ?string => $r->error)->color('danger')->placeholder('—'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('hogar'))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->filters([
                SelectFilter::make('estado')->options(EstadoMenu::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(AccionesEjecucion::todas()),
            ])
            ->emptyStateHeading('Todavía no hay menús')
            ->emptyStateDescription('Crea un menú, elige la semana y pulsa «Generar menú».');
    }
}
