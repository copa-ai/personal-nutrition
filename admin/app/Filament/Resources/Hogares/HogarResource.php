<?php

namespace App\Filament\Resources\Hogares;

use App\Filament\Resources\Hogares\Pages\CreateHogar;
use App\Filament\Resources\Hogares\Pages\EditHogar;
use App\Filament\Resources\Hogares\Pages\ListHogares;
use App\Filament\Resources\Hogares\RelationManagers\DisponibilidadRelationManager;
use App\Filament\Resources\Hogares\Schemas\HogarForm;
use App\Filament\Resources\Hogares\Tables\HogaresTable;
use App\Models\Hogar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class HogarResource extends Resource
{
    protected static ?string $model = Hogar::class;

    protected static ?string $slug = 'hogares';

    protected static ?string $modelLabel = 'hogar';

    protected static ?string $pluralModelLabel = 'hogares';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Hogar';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return HogarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HogaresTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DisponibilidadRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHogares::route('/'),
            'create' => CreateHogar::route('/create'),
            'edit' => EditHogar::route('/{record}/edit'),
        ];
    }
}
