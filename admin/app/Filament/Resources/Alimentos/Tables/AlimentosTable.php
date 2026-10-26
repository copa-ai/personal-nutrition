<?php

namespace App\Filament\Resources\Alimentos\Tables;

use App\Enums\CategoriaComida;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AlimentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('categoria')->label('Categoría')->badge()->sortable(),
                TextColumn::make('unidad')->label('Unidad'),
            ])
            ->defaultSort('nombre')
            ->filters([SelectFilter::make('categoria')->options(CategoriaComida::class)])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('El catálogo está vacío')
            ->emptyStateDescription('Los alimentos también se pueden crear al registrar una compra o una existencia.');
    }
}
