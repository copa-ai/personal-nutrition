<?php

namespace App\Filament\Resources\RegistrosComida\Tables;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RegistrosComidaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('perfil.nombre')->label('Perfil')->searchable()->sortable(),
                TextColumn::make('momento')->label('Momento')->badge(),
                TextColumn::make('origen')->label('Origen')->badge()->color('gray'),
                TextColumn::make('descripcion')->label('Qué se comió')->limit(70)->searchable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('perfil')->label('Perfil')->relationship('perfil', 'nombre'),
                SelectFilter::make('momento')->options(MomentoComida::class),
                SelectFilter::make('origen')->options(OrigenComida::class),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Todavía no has registrado nada')
            ->emptyStateDescription('Anota lo que comes de verdad para que el agente no repita y mantenga la variedad.');
    }
}
