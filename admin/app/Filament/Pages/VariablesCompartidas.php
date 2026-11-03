<?php

namespace App\Filament\Pages;

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
        return [
            ['nombre' => 'NUTRITION_DB_CLUSTER', 'valor' => config('nutrition.compartido.cluster'), 'secreto' => false,
                'ayuda' => 'Identifica la base de datos en la red Celaut («postgres:personal-nutrition»). Es pública: se anuncia a otros nodos.'],
            ['nombre' => 'NUTRITION_DB_PASSWORD', 'valor' => config('nutrition.compartido.clave_bd'), 'secreto' => true,
                'ayuda' => 'Contraseña de acceso a la base de datos.'],
            ['nombre' => 'APP_KEY', 'valor' => config('app.key'), 'secreto' => true,
                'ayuda' => 'Cifra el token de Claude y las sesiones guardadas en la base: debe ser la misma en todos los dispositivos.'],
        ];
    }

    /** La orden para arrancar otro dispositivo con estos valores (los secretos van en claro: es para copiar). */
    public static function orden(): string
    {
        $partes = collect(static::variables())->filter(fn (array $v) => filled($v['valor']))
            ->map(fn (array $v) => '-e '.$v['nombre'].' '.escapeshellarg((string) $v['valor']));

        return 'nodo execute '.$partes->implode(' ').' personal-nutrition';
    }

    public function getViewData(): array
    {
        return ['variables' => static::variables(), 'orden' => static::orden()];
    }
}
