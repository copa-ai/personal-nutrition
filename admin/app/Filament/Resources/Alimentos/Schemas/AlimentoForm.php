<?php

namespace App\Filament\Resources\Alimentos\Schemas;

use App\Enums\CategoriaComida;
use App\Models\Alimento;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AlimentoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(static::campos());
    }

    /**
     * Campos del alimento; también los usa el «crear al vuelo» de los Select de existencias.
     *
     * @return list<mixed>
     */
    public static function campos(): array
    {
        return [
            TextInput::make('nombre')->label('Nombre')->required()->maxLength(255)->unique(ignoreRecord: true)
                ->validationMessages(['unique' => 'Ya existe un alimento con ese nombre.']),
            Select::make('categoria')->label('Categoría')->options(CategoriaComida::class)->required()->default(CategoriaComida::Otro),
            Select::make('unidad')->label('Unidad habitual')->options(Alimento::UNIDADES)->required()->default('g'),
        ];
    }
}
