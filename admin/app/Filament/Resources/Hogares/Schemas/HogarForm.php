<?php

namespace App\Filament\Resources\Hogares\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class HogarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')->label('Nombre')->required()->maxLength(255),
                TextInput::make('platos_por_toma')->label('Platos por comida y cena')->helperText('Cuántos platos lleva cada opción de comida y cena al generar un menú (por ejemplo, 2: un primero y un segundo).')
                    ->numeric()->integer()->minValue(1)->maxValue(4)->default(2)->required(),
            ]);
    }
}
