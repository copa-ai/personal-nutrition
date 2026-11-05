<?php

namespace App\Filament\Pages;

use App\Models\Auditoria;
use App\Services\CopiaBase;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CopiaSeguridad extends Page
{
    protected string $view = 'filament.pages.copia-seguridad';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Copia de seguridad';

    protected static ?string $title = 'Copia de seguridad';

    protected static ?string $slug = 'copia-seguridad';

    protected static ?int $navigationSort = 10;

    public function getViewData(): array
    {
        return ['disponible' => CopiaBase::disponible(), 'postgres' => CopiaBase::esPostgres()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')->label('Descargar copia')->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn () => CopiaBase::disponible())
                ->action(function () {
                    $copia = app(CopiaBase::class);
                    $nombre = CopiaBase::nombreDescarga();

                    if (CopiaBase::esPostgres()) {
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
                        }, $nombre, ['Content-Type' => 'application/gzip']);
                    }

                    $ruta = $copia->exportar();
                    Auditoria::registrar('copia.exportada', 'Copia de seguridad exportada', ['formato' => 'sqlite', 'tablas' => null]);

                    return response()->download($ruta, $nombre)->deleteFileAfterSend();
                }),
            Action::make('importar')->label('Restaurar copia')->icon(Heroicon::OutlinedArrowUpTray)->color('danger')
                ->visible(fn () => CopiaBase::puedeImportar())
                ->modalHeading('Restaurar una copia de seguridad')
                ->modalDescription('Sustituye TODOS los datos actuales (hogares, menús, lo comido, usuarios…) por los de la copia. No se puede deshacer: descarga antes una copia de lo que hay ahora.')
                ->modalSubmitActionLabel('Sustituir datos')
                ->schema([
                    FileUpload::make('copia')->label('Archivo de copia (.sqlite)')->required()
                        ->disk('local')->directory('copias-importadas')->visibility('private')->maxSize(12 * 1024),
                ])
                ->action(function (array $data): void {
                    $disco = Storage::disk('local');
                    try {
                        $resumen = app(CopiaBase::class)->importar($disco->path($data['copia']));
                        Auditoria::registrar('copia.importada', 'Copia de seguridad restaurada', ['tablas' => count($resumen), 'filas' => array_sum($resumen)]);
                        Notification::make()->title('Copia restaurada')
                            ->body(array_sum($resumen).' filas en '.count($resumen).' tablas. Si cambió tu usuario, vuelve a iniciar sesión.')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('No se pudo restaurar')->body($e->getMessage())->danger()->persistent()->send();
                    } finally {
                        $disco->delete($data['copia']);
                    }
                }),
        ];
    }
}
