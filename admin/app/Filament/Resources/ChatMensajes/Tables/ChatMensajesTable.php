<?php

namespace App\Filament\Resources\ChatMensajes\Tables;

use App\Enums\EstadoChat;
use App\Models\ChatMensaje;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChatMensajesTable
{
    public static function etiquetaEstado(EstadoChat $estado): string
    {
        return match ($estado) {
            EstadoChat::Pendiente => 'Pendiente',
            EstadoChat::EnCurso => 'En curso',
            EstadoChat::Completado => 'Completado',
            EstadoChat::Fallido => 'Fallido',
            EstadoChat::Cancelado => 'Cancelado',
        };
    }

    public static function colorEstado(EstadoChat $estado): string
    {
        return match ($estado) {
            EstadoChat::Pendiente => 'gray',
            EstadoChat::EnCurso => 'warning',
            EstadoChat::Completado => 'success',
            EstadoChat::Fallido => 'danger',
            EstadoChat::Cancelado => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sequence', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('sequence')->label('Nº')->sortable(),
                TextColumn::make('from_service')->label('Quién')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Asistente' : 'Persona')
                    ->color(fn (bool $state): string => $state ? 'primary' : 'gray'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoChat $state): string => self::etiquetaEstado($state))
                    ->color(fn (EstadoChat $state): string => self::colorEstado($state)),
                TextColumn::make('texto')->label('Texto')->state(fn (ChatMensaje $r): string => $r->texto())->limit(90)->placeholder('—'),
                IconColumn::make('con_imagenes')->label('Fotos')->boolean()
                    ->state(fn (ChatMensaje $r): bool => $r->imagenes() !== []),
                TextColumn::make('created_ms')->label('Fecha')->sortable()
                    ->formatStateUsing(fn (int $state): string => date('d/m/Y H:i:s', intdiv($state, 1000))),
            ])
            ->filters([
                SelectFilter::make('estado')->label('Estado')
                    ->options(collect(EstadoChat::cases())->mapWithKeys(fn (EstadoChat $e): array => [$e->value => self::etiquetaEstado($e)])->all()),
                TernaryFilter::make('from_service')->label('Quién')->trueLabel('Asistente')->falseLabel('Persona')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->where('from_service', true),
                        false: fn (Builder $q): Builder => $q->where('from_service', false),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Todavía no hay mensajes en el chat');
    }
}
