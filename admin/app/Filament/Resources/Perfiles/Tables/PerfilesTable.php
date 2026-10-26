<?php

namespace App\Filament\Resources\Perfiles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PerfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('hogar.nombre')->label('Hogar')->sortable(),
                IconColumn::make('activo')->label('Activo')->boolean(),
                TextColumn::make('kcal_diarias')->label('Kcal/día')->numeric()->sortable(),
                TextColumn::make('restricciones')->label('Restricciones')->badge()->color('warning'),
            ])
            ->filters([
                SelectFilter::make('hogar')->label('Hogar')->relationship('hogar', 'nombre'),
                TernaryFilter::make('activo')->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay perfiles')
            ->emptyStateDescription('Crea un perfil por cada persona del hogar para la que se planifica.');
    }
}
