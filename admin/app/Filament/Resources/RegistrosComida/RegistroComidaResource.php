<?php

namespace App\Filament\Resources\RegistrosComida;

use App\Filament\Resources\RegistrosComida\Pages\CreateRegistroComida;
use App\Filament\Resources\RegistrosComida\Pages\EditRegistroComida;
use App\Filament\Resources\RegistrosComida\Pages\ListRegistrosComida;
use App\Filament\Resources\RegistrosComida\Schemas\RegistroComidaForm;
use App\Filament\Resources\RegistrosComida\Tables\RegistrosComidaTable;
use App\Models\RegistroComida;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RegistroComidaResource extends Resource
{
    protected static ?string $model = RegistroComida::class;

    protected static ?string $slug = 'lo-comido';

    protected static ?string $modelLabel = 'comida registrada';

    protected static ?string $pluralModelLabel = 'lo comido';

    protected static ?string $navigationLabel = 'Lo comido';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Seguimiento';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return RegistroComidaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegistrosComidaTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegistrosComida::route('/'),
            'create' => CreateRegistroComida::route('/create'),
            'edit' => EditRegistroComida::route('/{record}/edit'),
        ];
    }
}
