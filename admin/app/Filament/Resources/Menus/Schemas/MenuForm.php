<?php

namespace App\Filament\Resources\Menus\Schemas;

use App\Enums\EstadoMenu;
use App\Models\Hogar;
use App\Models\Menu;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        $lunes = fn (): CarbonImmutable => CarbonImmutable::now()->next(CarbonImmutable::MONDAY);

        return $schema
            ->columns(3)
            ->components([
                TextInput::make('nombre')->label('Nombre')->required()->maxLength(255)
                    ->default(fn (): string => 'Menú del '.$lunes()->locale('es')->isoFormat('D [de] MMMM')),
                Select::make('hogar_id')->label('Hogar')->relationship('hogar', 'nombre')->required()
                    ->default(fn (): ?int => Hogar::query()->value('id')),
                DatePicker::make('fecha_inicio')->label('Primer día del menú')->required()->native(false)->displayFormat('d/m/Y')
                    ->default($lunes)->helperText('El menú cubre los 7 días desde esta fecha.'),
            ])
            ->disabled(fn (?Menu $record): bool => $record !== null && ! in_array($record->estado, [EstadoMenu::Borrador, EstadoMenu::Fallido], true));
    }
}
