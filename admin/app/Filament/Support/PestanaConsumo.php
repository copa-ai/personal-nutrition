<?php

namespace App\Filament\Support;

use App\Models\Menu;
use App\Services\Menu\ResumenConsumo;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

/**
 * Pestaña «Consumo» de la ficha de un menú. La pinta la vista `filament.menus.consumo`, que lee ResumenConsumo::de($menu): tokens, modelo y
 * coste de cada respuesta, calculado con la tarifa vigente cuando se registró (congelado). Los menús sin libro (importados antes)
 * se muestran estimados con las tarifas actuales y la vista lo dice.
 *
 * Uso en el infolist del Resource:   ...Tabs::make('Detalle')->persistTabInQueryString()->tabs([ PestanaConsumo::tab(), ... ])
 */
final class PestanaConsumo
{
    /** Orden en que ocurren las fases de un trabajo, para listarlas cronológicamente y no por orden alfabético. */
    private const FASES = [
        'sin fase' => ['Inicio', 0],
        'lectura' => ['Lectura del encargo', 1],
        'planificacion' => ['Planificación', 2],
        'validacion' => ['Validación', 3],
        'terminada' => ['Entrega', 4],
    ];

    public static function tab(): Tab
    {
        return Tab::make('Consumo')->icon(Heroicon::OutlinedCpuChip)
            ->badge(fn (Menu $r): ?string => ($c = ResumenConsumo::de($r)['costes']['total'] ?? null) > 0 ? self::dinero($c) : null)
            ->schema([ViewEntry::make('consumo')->view('filament.menus.consumo')->hiddenLabel()]);
    }

    /** Nombre legible de una fase del trabajo del agente. */
    public static function etiquetaFase(string $fase): string
    {
        return self::FASES[$fase][0] ?? ucfirst($fase);
    }

    /** Posición de una fase en el trabajo (las desconocidas, al final). */
    public static function ordenFase(string $fase): int
    {
        return self::FASES[$fase][1] ?? 99;
    }

    /** Suma de todos los tokens de una fila de consumo (entrada, salida y caché). El razonamiento YA va dentro de la salida. */
    public static function sumaTokens(array $f): int
    {
        return (int) (($f['input_tokens'] ?? 0) + ($f['output_tokens'] ?? 0) + ($f['cache_read'] ?? 0) + ($f['cache_creation'] ?? 0));
    }

    /** USD con 3 decimales bajo 1 y 2 por encima; un importe distinto de cero pero menor de $0,001 se dice así. null = sin tarifa: nunca se inventa un 0. */
    public static function dinero(?float $usd): string
    {
        return match (true) {
            $usd === null => 'Sin tarifa',
            $usd > 0 && $usd < 0.0005 => '< $0,001',
            default => '$'.number_format($usd, $usd < 1 ? 3 : 2, ',', '.'),
        };
    }

    /** Duración corta legible: «44 s», «5 min 12 s», «1 h 3 min». */
    public static function duracion(int $segundos): string
    {
        return match (true) {
            $segundos < 60 => "{$segundos} s",
            $segundos < 3600 => intdiv($segundos, 60).' min'.($segundos % 60 ? ' '.($segundos % 60).' s' : ''),
            default => intdiv($segundos, 3600).' h'.(intdiv($segundos % 3600, 60) ? ' '.intdiv($segundos % 3600, 60).' min' : ''),
        };
    }
}
