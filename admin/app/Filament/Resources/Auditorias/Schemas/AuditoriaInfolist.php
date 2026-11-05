<?php

namespace App\Filament\Resources\Auditorias\Schemas;

use App\Filament\Resources\Auditorias\Tables\AuditoriasTable;
use App\Models\Auditoria;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditoriaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Movimiento')->columns(3)->schema([
                TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('evento')->label('Evento')->badge()
                    ->formatStateUsing(fn (string $state): string => AuditoriasTable::etiquetaEvento($state))
                    ->color(fn (string $state): string => AuditoriasTable::colorEvento($state)),
                TextEntry::make('user_nombre')->label('Usuario')->placeholder('Sin usuario')
                    ->formatStateUsing(fn (?string $state, Auditoria $record): string => trim(($state ?? '').' '.($record->user_email ? '<'.$record->user_email.'>' : ''))),
                TextEntry::make('auditable_type')->label('Tipo de objeto')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                TextEntry::make('auditable_id')->label('Id del objeto')->placeholder('—'),
                TextEntry::make('descripcion')->label('Descripción')->placeholder('—'),
                TextEntry::make('ip')->label('IP')->placeholder('—'),
                TextEntry::make('metodo')->label('Método')->placeholder('—'),
                TextEntry::make('instancia')->label('Instancia')->placeholder('—'),
                TextEntry::make('url')->label('URL')->placeholder('—'),
                TextEntry::make('user_agent')->label('Navegador')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Cambios')->schema([
                KeyValueEntry::make('cambios')->label('Campo y valores')
                    ->keyLabel('Campo')->valueLabel('Antes → después')
                    ->state(fn (Auditoria $record): array => $record->diferencias())
                    ->placeholder('Sin cambios de valores'),
            ]),
            Section::make('Datos adicionales')->schema([
                KeyValueEntry::make('datos_legibles')->label('Datos')
                    ->keyLabel('Clave')->valueLabel('Valor')
                    ->state(fn (Auditoria $record): array => $record->datosLegibles())
                    ->placeholder('Sin datos adicionales'),
            ]),
        ]);
    }
}
