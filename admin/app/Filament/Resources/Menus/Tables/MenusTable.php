<?php

namespace App\Filament\Resources\Menus\Tables;

use App\Enums\EstadoMenu;
use App\Filament\Support\AccionesEjecucion;
use App\Models\Menu;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Menú')->formatStateUsing(fn (Menu $r): string => $r->titulo())->searchable()->sortable(),
                TextColumn::make('hogar.nombre')->label('Hogar')->toggleable(),
                TextColumn::make('fecha_inicio')->label('Semana desde')->date('d/m/Y')->sortable(),
                TextColumn::make('estado')->label('Estado')->badge()->formatStateUsing(fn (Menu $r): string => $r->etiquetaEstado()),
                TextColumn::make('iniciado_en')->label('Iniciado')->since()->placeholder('—')->sortable(),
                TextColumn::make('updated_at')->label('Actualizado')->since()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error')->label('Error')->limit(60)->tooltip(fn (Menu $r): ?string => $r->error)->color('danger')->placeholder('—'),
            ])
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
