<?php

namespace App\Filament\Resources\Existencias\Schemas;

use App\Filament\Resources\Alimentos\Schemas\AlimentoForm;
use App\Models\Alimento;
use App\Models\Hogar;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExistenciaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('hogar_id')->label('Hogar')->relationship('hogar', 'nombre')->required()
                    ->default(fn (): ?int => Hogar::query()->value('id')),
                Select::make('alimento_id')->label('Alimento')->relationship('alimento', 'nombre')->required()
                    ->searchable()->preload()->createOptionForm(AlimentoForm::campos()),
                TextInput::make('cantidad')->label('Cantidad')->numeric()->minValue(0)->required(),
                Select::make('unidad')->label('Unidad')->options(Alimento::UNIDADES)->required()->default('g'),
                DatePicker::make('caduca_el')->label('Caduca el')->native(false)->displayFormat('d/m/Y'),
            ]);
    }
}
