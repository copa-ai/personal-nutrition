<?php

namespace App\Filament\Resources\Perfiles\Schemas;

use App\Models\Hogar;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PerfilForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Persona')->columns(2)->schema([
                    Select::make('hogar_id')->label('Hogar')->relationship('hogar', 'nombre')->required()
                        ->default(fn (): ?int => Hogar::query()->value('id')),
                    TextInput::make('nombre')->label('Nombre')->required()->maxLength(255),
                    Toggle::make('activo')->label('Activo')->default(true)
                        ->helperText('Un perfil inactivo no entra en los menús nuevos.'),
                ]),
                Section::make('Modelo nutricional')
                    ->description('Lo que el agente usa para adaptar cada receta a esta persona.')
                    ->columns(4)->schema([
                        Textarea::make('objetivo')->label('Objetivo')->rows(3)->columnSpanFull()
                            ->placeholder('Ej.: bajar de peso poco a poco, mucha verdura y proteína magra.'),
                        TextInput::make('kcal_diarias')->label('Kcal al día')->numeric()->minValue(500)->maxValue(10000),
                        TextInput::make('proteina_pct')->label('Proteína %')->numeric()->minValue(0)->maxValue(100),
                        TextInput::make('carbohidratos_pct')->label('Carbohidratos %')->numeric()->minValue(0)->maxValue(100),
                        TextInput::make('grasa_pct')->label('Grasa %')->numeric()->minValue(0)->maxValue(100)
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $macros = [$get('proteina_pct'), $get('carbohidratos_pct'), $value];
                                if (count(array_filter($macros, fn (mixed $m): bool => filled($m))) > 0 && abs(array_sum($macros) - 100) > 0.001) {
                                    $fail('Los tres porcentajes de macros deben sumar 100 (ahora suman '.array_sum($macros).').');
                                }
                            }]),
                        TagsInput::make('restricciones')->label('Restricciones')->columnSpanFull()
                            ->placeholder('Escribe y pulsa Intro: sin lácteos, sin gluten…')
                            ->helperText('Límites que el agente trata como obligatorios. No sustituyen el consejo de un profesional sanitario.'),
                        Textarea::make('notas')->label('Notas')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }
}
