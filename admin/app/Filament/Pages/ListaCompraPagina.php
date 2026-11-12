<?php

namespace App\Filament\Pages;

use App\Services\Compra\ListaCompra;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * La lista de la compra del menú activo en solo lectura (útil en el móvil): lo que falta tras restar la despensa, agrupado por pasillo.
 * Se marca como comprado desde el mazo de la app de chat (interfaz «cards»); aquí solo se mira. Usa el mismo cálculo (ListaCompra).
 */
class ListaCompraPagina extends Page
{
    protected string $view = 'filament.pages.lista-compra';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Seguimiento';

    protected static ?string $navigationLabel = 'Lista de la compra';

    protected static ?string $title = 'Lista de la compra';

    protected static ?string $slug = 'lista-compra';

    protected static ?int $navigationSort = 1;

    public function getViewData(): array
    {
        $lista = app(ListaCompra::class);
        $hogar = $lista->hogar();
        $grupos = [];
        foreach ($lista->calcular($hogar) as $fila) {
            $texto = $fila['faltante'] === null ? 'sin cantidad fija' : ListaCompra::formatear((float) $fila['faltante'], $fila['familia'], (string) $fila['unidad']);
            $grupos[ListaCompra::pasillo((string) $fila['categoria'])][] = ['nombre' => $fila['nombre'], 'falta' => $texto];
        }

        return ['menu' => $hogar?->menuActivo?->nombre, 'grupos' => $grupos];
    }
}
