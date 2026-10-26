<?php

namespace App\Filament\Resources\RegistrosComida\Schemas;

use App\Enums\CategoriaComida;
use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/** Lo que alguien comió de verdad. Es lo que el agente usa como historial: el menú planificado no cuenta como comido. */
class RegistroComidaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('perfil_id')->label('Perfil')->relationship('perfil', 'nombre')->required()->searchable()->preload(),
                DatePicker::make('fecha')->label('Fecha')->required()->default(now())->native(false)->displayFormat('d/m/Y'),
                Select::make('momento')->label('Momento')->options(MomentoComida::class)->required()->default(MomentoComida::Comida),
                Select::make('origen')->label('Origen')->options(OrigenComida::class)->required()->default(OrigenComida::Casa)
                    ->helperText('Si no seguiste el menú o comiste fuera, indícalo: el agente no da por comido lo planificado.'),
                Textarea::make('descripcion')->label('Qué se comió')->required()->rows(3)->columnSpanFull(),
                CheckboxList::make('categorias')->label('Categorías')->options(CategoriaComida::class)->columns(4)->columnSpanFull(),
            ]);
    }
}
