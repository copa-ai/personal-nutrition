<?php

namespace App\Filament\Pages;

use App\Models\Auditoria;
use App\Services\CopiaBase;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class CopiaSeguridad extends Page
{
    protected string $view = 'filament.pages.copia-seguridad';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Copia de seguridad';

    protected static ?string $title = 'Copia de seguridad';

    protected static ?string $slug = 'copia-seguridad';

    protected static ?int $navigationSort = 10;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')->label('Descargar copia')->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $copia = app(CopiaBase::class);
                    $tablas = 0;

                    return response()->streamDownload(function () use ($copia, &$tablas): void {
                        $tablas = $copia->volcarGzip(function (string $trozo): void {
                            echo $trozo;
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                        });
                        Auditoria::registrar('copia.exportada', 'Copia de seguridad exportada', ['formato' => 'sql.gz', 'tablas' => $tablas]);
                    }, CopiaBase::nombreDescarga(), ['Content-Type' => 'application/gzip']);
                }),
        ];
    }
}
