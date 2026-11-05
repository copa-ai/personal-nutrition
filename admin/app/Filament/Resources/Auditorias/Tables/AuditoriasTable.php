<?php

namespace App\Filament\Resources\Auditorias\Tables;

use App\Models\Auditoria;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditoriasTable
{
    private const ETIQUETAS = [
        'login' => 'Inicio de sesión',
        'logout' => 'Cierre de sesión',
        'login_fallido' => 'Acceso fallido',
        'bloqueo' => 'Bloqueo',
        'contrasena_restablecida' => 'Contraseña restablecida',
        'creado' => 'Creado',
        'actualizado' => 'Actualizado',
        'eliminado' => 'Eliminado',
        'restaurado' => 'Restaurado',
    ];

    public static function etiquetaEvento(string $evento): string
    {
        return self::ETIQUETAS[$evento] ?? $evento;
    }

    public static function colorEvento(string $evento): string
    {
        return match ($evento) {
            'login', 'creado', 'restaurado' => 'success',
            'logout' => 'gray',
            'login_fallido', 'bloqueo', 'eliminado' => 'danger',
            'actualizado', 'contrasena_restablecida' => 'warning',
            default => 'primary',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('user_nombre')->label('Usuario')->placeholder('Sin usuario')->searchable(['user_nombre', 'user_email']),
                TextColumn::make('evento')->label('Evento')->badge()->searchable()
                    ->formatStateUsing(fn (string $state): string => self::etiquetaEvento($state))
                    ->color(fn (string $state): string => self::colorEvento($state)),
                TextColumn::make('auditable_type')->label('Objeto')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, Auditoria $record): string => $state ? class_basename($state).' #'.$record->auditable_id : '—'),
                TextColumn::make('instancia')->label('Instancia')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('ip')->label('IP')->placeholder('—')->searchable(),
                TextColumn::make('descripcion')->label('Descripción')->placeholder('—')->limit(60)->searchable(),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Usuario')
                    ->options(fn (): array => Auditoria::query()->whereNotNull('user_id')
                        ->selectRaw('user_id, max(user_nombre) as nombre')->groupBy('user_id')->pluck('nombre', 'user_id')->all()),
                SelectFilter::make('evento')->label('Evento')
                    ->options(fn (): array => Auditoria::query()->distinct()->orderBy('evento')->pluck('evento')
                        ->mapWithKeys(fn (string $e): array => [$e => self::etiquetaEvento($e)])->all()),
                SelectFilter::make('auditable_type')->label('Tipo de objeto')
                    ->options(fn (): array => Auditoria::query()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type')
                        ->mapWithKeys(fn (string $t): array => [$t => class_basename($t)])->all()),
                Filter::make('fecha')->label('Fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('created_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Todavía no hay movimientos registrados');
    }
}
