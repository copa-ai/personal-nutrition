<?php

namespace App\Filament\Resources\RegistrosComida\Tables;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Models\RegistroComida;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;

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
                ...(Schema::hasColumn('registros_comida', 'foto_hash') ? [
                    ImageColumn::make('foto_hash')->label('Foto')->square()->height(40)
                        ->state(fn (RegistroComida $r): ?string => preg_match('/^[0-9a-f]{64}$/', (string) $r->foto_hash) ? route('fotos', $r->foto_hash) : null),
                ] : []),
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
