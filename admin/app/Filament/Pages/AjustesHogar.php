<?php

namespace App\Filament\Pages;

use App\Models\Hogar;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** El hogar de la aplicación (uno solo, ver Hogar::unico): su nombre y cuántos platos lleva cada comida y cena. Cada campo se guarda al salir de él. */
class AjustesHogar extends Page
{
    protected string $view = 'filament.pages.ajustes-hogar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|\UnitEnum|null $navigationGroup = 'Hogar';

    protected static ?string $navigationLabel = 'Mi hogar';

    protected static ?string $title = 'Mi hogar';

    protected static ?string $slug = 'hogar';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(Hogar::unico()->only(['nombre', 'platos_por_toma']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(2)->components([
            TextInput::make('nombre')->label('Nombre')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state) => filled($state) ? Hogar::unico()->update(['nombre' => trim($state)]) : null),
            TextInput::make('platos_por_toma')->label('Platos por comida y cena')->numeric()->integer()->minValue(1)->maxValue(4)->required()->live(onBlur: true)
                ->helperText('Cuántos platos lleva cada opción de comida y cena al generar un menú (por ejemplo, 2: un primero y un segundo).')
                ->afterStateUpdated(function ($state): void {
                    if (is_numeric($state) && (int) $state >= 1 && (int) $state <= 4) {
                        Hogar::unico()->update(['platos_por_toma' => (int) $state]);
                    }
                }),
        ]);
    }
}
