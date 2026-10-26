<?php

namespace App\Filament\Resources\Menus\Schemas;

use App\Enums\EstadoMenu;
use App\Filament\Support\PestanaConsumo;
use App\Models\Menu;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Ficha de un menú, de arriba abajo: resumen (estado, semana, cifras; error o avance si procede) y cuatro pestañas:
 * Menú (elegir una opción por toma), Compra (lo que hay que comprar con lo elegido), Registro (lo que hace el agente) y Consumo (coste).
 * Mientras el menú se genera, o si falló y hay registro, se abre la pestaña Registro; en cualquier otro caso, la del Menú.
 */
class MenuInfolist
{
    private const PESTANA_MENU = 1;

    private const PESTANA_REGISTRO = 3;

    /** Mientras se genera, o si falló y hay un registro que explique por qué. Si no, el menú (o su estado vacío) es lo que interesa. */
    private static function quiereVerElRegistro(Menu $menu): bool
    {
        return $menu->estado->enCurso() || ($menu->estado === EstadoMenu::Fallido && $menu->archivo_log && is_file($menu->archivo_log));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            ViewEntry::make('poll')->view('filament.menus.poll')->hiddenLabel()
                ->visible(fn (Menu $r): bool => $r->estado->enCurso()),   // solo hace falta refrescar mientras corre
            ViewEntry::make('resumen')->view('filament.menus.resumen')->hiddenLabel(),
            Tabs::make('Detalle')->persistTabInQueryString()
                ->activeTab(fn (Menu $r): int => self::quiereVerElRegistro($r) ? self::PESTANA_REGISTRO : self::PESTANA_MENU)
                ->tabs([
                    Tab::make('Menú')->icon(Heroicon::OutlinedCalendarDays)->schema([
                        ViewEntry::make('menu')->view('filament.menus.menu')->hiddenLabel(),
                    ]),
                    Tab::make('Compra')->icon(Heroicon::OutlinedShoppingCart)
                        ->badge(fn (Menu $r): ?int => $r->contenido !== null ? (count($r->listaCompra()['comprar']) ?: null) : null)
                        ->schema([ViewEntry::make('compra')->view('filament.menus.compra')->hiddenLabel()]),
                    Tab::make('Registro')->icon(Heroicon::OutlinedCommandLine)->schema([
                        ViewEntry::make('registro_agente')->view('filament.menus.registro')->hiddenLabel(),
                    ]),
                    PestanaConsumo::tab(),
                ]),
        ]);
    }
}
