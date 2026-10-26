<?php

namespace App\Filament\Resources\Compras\Schemas;

use App\Filament\Resources\Alimentos\Schemas\AlimentoForm;
use App\Models\Alimento;
use App\Models\Hogar;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompraForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compra')->columns(3)->schema([
                    Select::make('hogar_id')->label('Hogar')->relationship('hogar', 'nombre')->required()
                        ->default(fn (): ?int => Hogar::query()->value('id')),
                    DatePicker::make('fecha')->label('Fecha')->required()->default(now())->native(false)->displayFormat('d/m/Y'),
                    TextInput::make('tienda')->label('Tienda')->maxLength(255),
                    Textarea::make('notas')->label('Notas')->rows(2)->columnSpanFull(),
                ]),
                Section::make('Líneas')
                    ->description('Cada línea nueva suma su cantidad a las existencias. Corregir o borrar una línea después NO cambia el stock: ajústalo en Existencias.')
                    ->schema([
                        Repeater::make('lineas')->relationship('lineas')->label('Qué se ha comprado')->required()->minItems(1)
                            ->columns(4)->addActionLabel('Añadir línea')
                            ->schema([
                                Select::make('alimento_id')->label('Alimento')->options(fn (): array => Alimento::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                                    ->required()->searchable()
                                    ->createOptionForm(AlimentoForm::campos())
                                    ->createOptionUsing(fn (array $data): int => Alimento::query()->create($data)->getKey()),
                                TextInput::make('cantidad')->label('Cantidad')->numeric()->minValue(0.01)->required(),
                                Select::make('unidad')->label('Unidad')->options(Alimento::UNIDADES)->required()->default('g'),
                                DatePicker::make('caduca_el')->label('Caduca el')->native(false)->displayFormat('d/m/Y'),
                            ]),
                    ]),
            ]);
    }
}
