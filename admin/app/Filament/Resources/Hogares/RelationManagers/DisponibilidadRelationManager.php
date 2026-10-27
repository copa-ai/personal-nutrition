<?php

namespace App\Filament\Resources\Hogares\RelationManagers;

use App\Enums\DiaSemana;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Minutos que el hogar tiene para cocinar «al momento» cada toma de cada día de la semana. Todas las recetas del menú tienen que caber aquí. */
class DisponibilidadRelationManager extends RelationManager
{
    protected static string $relationship = 'disponibilidad';

    protected static ?string $title = 'Tiempos de cocinado';

    protected static ?string $modelLabel = 'día';

    protected static ?string $pluralModelLabel = 'días';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('dia_semana')->label('Día')->options(DiaSemana::class)->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('hogar_id', $this->getOwnerRecord()->getKey()))
                    ->validationMessages(['unique' => 'Ese día ya tiene sus tiempos definidos.']),
                TextInput::make('minutos_desayuno')->label('Minutos para el desayuno')->numeric()->minValue(0)->maxValue(600)->suffix('min')
                    ->helperText('Déjalo vacío si no hay límite: el agente propondrá algo sencillo o preparado el día antes.'),
                TextInput::make('minutos_almuerzo')->label('Minutos para el almuerzo')->numeric()->minValue(0)->maxValue(600)->suffix('min')
                    ->helperText('Déjalo vacío si no hay límite: el agente propondrá algo sencillo o preparado el día antes.'),
                TextInput::make('minutos_comida')->label('Minutos para la comida')->numeric()->minValue(0)->maxValue(600)->suffix('min')
                    ->helperText('Déjalo vacío si ese día no se cocina la comida.'),
                TextInput::make('minutos_merienda')->label('Minutos para la merienda')->numeric()->minValue(0)->maxValue(600)->suffix('min')
                    ->helperText('Déjalo vacío si no hay límite: el agente propondrá algo sencillo o preparado el día antes.'),
                TextInput::make('minutos_cena')->label('Minutos para la cena')->numeric()->minValue(0)->maxValue(600)->suffix('min')
                    ->helperText('Déjalo vacío si ese día no se cocina la cena.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('dia_semana')
            ->columns([
                TextColumn::make('dia_semana')->label('Día')->badge()->sortable(),
                TextColumn::make('minutos_desayuno')->label('Desayuno')->suffix(' min')->placeholder('Sin dato'),
                TextColumn::make('minutos_almuerzo')->label('Almuerzo')->suffix(' min')->placeholder('Sin dato'),
                TextColumn::make('minutos_comida')->label('Comida')->suffix(' min')->placeholder('No se cocina'),
                TextColumn::make('minutos_merienda')->label('Merienda')->suffix(' min')->placeholder('Sin dato'),
                TextColumn::make('minutos_cena')->label('Cena')->suffix(' min')->placeholder('No se cocina'),
            ])
            ->defaultSort('dia_semana')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Sin tiempos definidos')
            ->emptyStateDescription('Sin tiempos el agente no puede planificar el menú. Añade al menos un día.');
    }
}
