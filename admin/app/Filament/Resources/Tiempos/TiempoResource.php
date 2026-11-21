<?php

namespace App\Filament\Resources\Tiempos;

use App\Enums\DiaSemana;
use App\Filament\Resources\Tiempos\Pages\CreateTiempo;
use App\Filament\Resources\Tiempos\Pages\EditTiempo;
use App\Filament\Resources\Tiempos\Pages\ListTiempos;
use App\Models\DisponibilidadDia;
use App\Models\Hogar;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** Minutos que el hogar tiene para cocinar «al momento» cada toma de cada día de la semana. Todas las recetas del menú tienen que caber aquí. */
class TiempoResource extends Resource
{
    protected static ?string $model = DisponibilidadDia::class;

    protected static ?string $slug = 'tiempos';

    protected static ?string $modelLabel = 'día';

    protected static ?string $pluralModelLabel = 'tiempos de cocinado';

    protected static ?string $navigationLabel = 'Tiempos de cocinado';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Hogar';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $minutos = fn (string $campo, string $etiqueta, string $ayuda): TextInput => TextInput::make($campo)->label($etiqueta)->numeric()->minValue(0)->maxValue(600)->suffix('min')->helperText($ayuda);
        $sinLimite = 'Déjalo vacío si no hay límite: el agente propondrá algo sencillo o preparado el día antes.';
        $noSeCocina = fn (string $toma): string => "Déjalo vacío si ese día no se cocina la {$toma}.";

        return $schema->components([
            Select::make('dia_semana')->label('Día')->options(DiaSemana::class)->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('hogar_id', Hogar::unico()->getKey()))
                ->validationMessages(['unique' => 'Ese día ya tiene sus tiempos definidos.']),
            $minutos('minutos_desayuno', 'Minutos para el desayuno', $sinLimite),
            $minutos('minutos_almuerzo', 'Minutos para el almuerzo', $sinLimite),
            $minutos('minutos_comida', 'Minutos para la comida', $noSeCocina('comida')),
            $minutos('minutos_merienda', 'Minutos para la merienda', $sinLimite),
            $minutos('minutos_cena', 'Minutos para la cena', $noSeCocina('cena')),
        ]);
    }

    public static function table(Table $table): Table
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
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Sin tiempos definidos')
            ->emptyStateDescription('Sin tiempos el agente no puede planificar el menú. Añade al menos un día.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTiempos::route('/'),
            'create' => CreateTiempo::route('/create'),
            'edit' => EditTiempo::route('/{record}/edit'),
        ];
    }
}
