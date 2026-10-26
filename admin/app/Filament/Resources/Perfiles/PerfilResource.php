<?php

namespace App\Filament\Resources\Perfiles;

use App\Filament\Resources\Perfiles\Pages\CreatePerfil;
use App\Filament\Resources\Perfiles\Pages\EditPerfil;
use App\Filament\Resources\Perfiles\Pages\ListPerfiles;
use App\Filament\Resources\Perfiles\RelationManagers\FeedbackRelationManager;
use App\Filament\Resources\Perfiles\Schemas\PerfilForm;
use App\Filament\Resources\Perfiles\Tables\PerfilesTable;
use App\Models\Perfil;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PerfilResource extends Resource
{
    protected static ?string $model = Perfil::class;

    protected static ?string $slug = 'perfiles';

    protected static ?string $modelLabel = 'perfil';

    protected static ?string $pluralModelLabel = 'perfiles';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Hogar';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return PerfilForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PerfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FeedbackRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPerfiles::route('/'),
            'create' => CreatePerfil::route('/create'),
            'edit' => EditPerfil::route('/{record}/edit'),
        ];
    }
}
