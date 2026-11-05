<?php

namespace App\Filament\Pages;

use App\Services\RedCompartida;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Las variables que tienen que ser iguales en todos los dispositivos que comparten la base de datos, para copiarlas al
 * arrancar uno nuevo (`nodo execute -e CLAVE valor ...`). Ver docker/servicio/start.sh y README («Base de datos compartida»).
 */
class VariablesCompartidas extends Page
{
    protected string $view = 'filament.pages.variables-compartidas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Variables compartidas';

    protected static ?string $title = 'Variables compartidas entre dispositivos';

    protected static ?string $slug = 'variables-compartidas';

    protected static ?int $navigationSort = 11;

    /**
     * @return list<array{nombre: string, valor: ?string, secreto: bool, ayuda: string}>
     */
    public static function variables(): array
    {
        $cluster = config('nutrition.compartido.cluster');
        $claveBd = config('nutrition.compartido.clave_bd');
        $appKey = config('app.key');

        return [
            ['nombre' => 'NUTRITION_RED', 'valor' => filled($cluster) && filled($claveBd) && filled($appKey)
                ? RedCompartida::codificar((string) $appKey, (string) $cluster, (string) $claveBd) : null, 'secreto' => true,
                'ayuda' => 'Lo único que hace falta copiar (cabe en un QR). Lleva APP_KEY, la clave de la red y la contraseña de la base: trátala como una contraseña.'],
        ];
    }

    /** La orden para arrancar otro dispositivo con este valor (va en claro: es para copiar). */
    public static function orden(): string
    {
        $valor = static::variables()[0]['valor'];

        return 'nodo execute '.(filled($valor) ? '-e NUTRITION_RED '.escapeshellarg($valor).' ' : '').'personal-nutrition';
    }

    public function getViewData(): array
    {
        return ['variables' => static::variables(), 'orden' => static::orden()];
    }
}
