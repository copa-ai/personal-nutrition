<?php

namespace App\Filament\Pages;

use App\Services\Menu\TokenClaude;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ConexionClaude extends Page
{
    protected string $view = 'filament.pages.conexion-claude';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Conexión con Claude';

    protected static ?string $title = 'Conexión con Claude';

    protected static ?string $slug = 'conexion-claude';

    protected static ?int $navigationSort = 9;

    public function getViewData(): array
    {
        return ['configurado' => TokenClaude::existe()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pegar')->label(TokenClaude::existe() ? 'Cambiar clave' : 'Pegar clave')->icon(Heroicon::OutlinedKey)
                ->modalHeading('Clave de conexión con Claude')
                ->modalDescription('Pega aquí la clave de conexión que te ha facilitado el equipo técnico.')
                ->schema([TextInput::make('token')->label('Clave')->password()->revealable()->required()->minLength(20)])
                ->action(function (array $data): void {
                    TokenClaude::guardar($data['token']);
                    Notification::make()->title('Clave guardada')->body('Usa «Probar conexión» para comprobarlo.')->success()->send();
                }),
            Action::make('probar')->label('Probar conexión')->icon(Heroicon::OutlinedBolt)->color('gray')
                ->requiresConfirmation()->modalDescription('Lanza una consulta mínima a Claude (gasta una gota del límite del plan).')
                ->action(function (): void {
                    $r = TokenClaude::probar();
                    $n = Notification::make()->title($r['ok'] ? 'Claude responde' : 'Claude no responde')->body($r['mensaje']);
                    ($r['ok'] ? $n->success() : $n->danger()->persistent())->send();
                }),
            Action::make('quitar')->label('Quitar clave')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn () => TokenClaude::existe())->requiresConfirmation()
                ->action(function (): void {
                    TokenClaude::quitar();
                    Notification::make()->title('Clave quitada')->success()->send();
                }),
        ];
    }
}
