<?php

namespace App\Filament\Resources\Existencias;

use App\Filament\Resources\Existencias\Pages\CreateExistencia;
use App\Filament\Resources\Existencias\Pages\EditExistencia;
use App\Filament\Resources\Existencias\Pages\ListExistencias;
use App\Filament\Resources\Existencias\Schemas\ExistenciaForm;
use App\Filament\Resources\Existencias\Tables\ExistenciasTable;
use App\Models\Existencia;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ExistenciaResource extends Resource
{
    protected static ?string $model = Existencia::class;

    protected static ?string $modelLabel = 'existencia';

    protected static ?string $pluralModelLabel = 'existencias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Despensa';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ExistenciaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExistenciasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExistencias::route('/'),
            'create' => CreateExistencia::route('/create'),
            'edit' => EditExistencia::route('/{record}/edit'),
        ];
    }
}
