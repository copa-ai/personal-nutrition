<?php

namespace App\Filament\Resources\Alimentos;

use App\Filament\Resources\Alimentos\Pages\CreateAlimento;
use App\Filament\Resources\Alimentos\Pages\EditAlimento;
use App\Filament\Resources\Alimentos\Pages\ListAlimentos;
use App\Filament\Resources\Alimentos\Schemas\AlimentoForm;
use App\Filament\Resources\Alimentos\Tables\AlimentosTable;
use App\Models\Alimento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AlimentoResource extends Resource
{
    protected static ?string $model = Alimento::class;

    protected static ?string $modelLabel = 'alimento';

    protected static ?string $pluralModelLabel = 'alimentos';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Despensa';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return AlimentoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlimentosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlimentos::route('/'),
            'create' => CreateAlimento::route('/create'),
            'edit' => EditAlimento::route('/{record}/edit'),
        ];
    }
}
