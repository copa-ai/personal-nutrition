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
            ]);
    }
}
