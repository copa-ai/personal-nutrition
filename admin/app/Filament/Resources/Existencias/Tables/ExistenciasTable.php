<?php

namespace App\Filament\Resources\Existencias\Tables;

use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExistenciasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alimento.nombre')->label('Alimento')->searchable()->sortable(),
                TextColumn::make('alimento.categoria')->label('Categoría')->badge()->toggleable(),
                TextColumn::make('cantidad')->label('Cantidad')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('unidad')->label('Unidad'),
                TextColumn::make('caduca_el')->label('Caduca')->date('d/m/Y')->sortable()->placeholder('—')
                    ->color(fn (?CarbonInterface $state): ?string => match (true) {
                        $state === null => null,
                        $state->isPast() => 'danger',
                        $state->diffInDays(now()) <= 3 => 'warning',
                        default => null,
                    }),
                TextColumn::make('hogar.nombre')->label('Hogar')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('alimento.nombre')
            ->filters([SelectFilter::make('hogar')->label('Hogar')->relationship('hogar', 'nombre')])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay nada en la despensa')
            ->emptyStateDescription('Se llena al registrar compras; también puedes añadir o corregir existencias a mano.');
    }
}
