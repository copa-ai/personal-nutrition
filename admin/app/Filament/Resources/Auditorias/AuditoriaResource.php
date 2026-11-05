<?php

namespace App\Filament\Resources\Auditorias;

use App\Filament\Resources\Auditorias\Pages\ListAuditorias;
use App\Filament\Resources\Auditorias\Pages\ViewAuditoria;
use App\Filament\Resources\Auditorias\Schemas\AuditoriaInfolist;
use App\Filament\Resources\Auditorias\Tables\AuditoriasTable;
use App\Models\Auditoria;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Registro de auditoria: solo lectura (listar y ver). */
class AuditoriaResource extends Resource
{
    protected static ?string $model = Auditoria::class;

    protected static ?string $slug = 'auditorias';

    protected static ?string $modelLabel = 'registro de auditoría';

    protected static ?string $pluralModelLabel = 'registros de auditoría';

    protected static ?string $navigationLabel = 'Auditoría';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?int $navigationSort = 91;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditoriaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditoriasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditorias::route('/'),
            'view' => ViewAuditoria::route('/{record}'),
        ];
    }
}
