<?php

namespace App\Filament\Resources\RegistrosComida\Pages;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Filament\Resources\RegistrosComida\RegistroComidaResource;
use App\Filament\Resources\RegistrosComida\Schemas\RegistroComidaForm;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\RegistroComida;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateRegistroComida extends CreateRecord
{
    protected static string $resource = RegistroComidaResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Guardar'),
            Action::make('otra_cosa')->label('Otra cosa')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->visible(fn (): bool => ! RegistroComidaForm::esOtraCosa($this->data))
                ->action(function (): void {
                    $this->data = [...$this->data, 'otra_cosa' => true, 'descripcion' => '', 'categorias' => [], 'origen' => OrigenComida::Casa->value];
                }),
            Action::make('usar_programada')->label('Volver a lo programado')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn (): bool => ! empty($this->data['otra_cosa']) && RegistroComidaForm::programadaDe($this->data) !== null)
                ->action(function (): void {
                    $this->data = [...$this->data, 'otra_cosa' => false, ...RegistroComidaForm::valoresProgramados($this->data),
                        'valor_programado' => RegistroComidaForm::valoresProgramados($this->data)['descripcion']];
                }),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Un registro por perfil. Con lo programado, el registro sale del menú activo (origen menú, su opción y su título). Con «otra cosa»
     * sale de lo escrito y, si había algo programado en esa toma, se pide al agente un intercambio para reajustar el menú.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $perfiles = Perfil::query()->whereIn('id', $data['perfiles'])->orderBy('nombre')->get();
        $estado = ['hogar_id' => $perfiles->first()?->hogar_id, 'fecha' => $data['fecha'], 'momento' => $data['momento'], 'otra_cosa' => $data['otra_cosa'] ?? false];
        $programada = RegistroComidaForm::programadaDe($estado);
        $otraCosa = RegistroComidaForm::esOtraCosa($estado);

        $comun = ['fecha' => $data['fecha'], 'momento' => $data['momento']] + ($otraCosa
            ? ['origen' => $data['origen'] ?? OrigenComida::Casa->value, 'descripcion' => $data['descripcion'], 'categorias' => ($data['categorias'] ?? []) ?: null]
            : ['origen' => OrigenComida::Menu->value, 'descripcion' => $programada['titulo'], 'categorias' => $programada['categorias'] ?: null,
                'menu_id' => $programada['menu']->id, 'opcion_ref' => $programada['ref']]);

        $registros = $perfiles->map(fn (Perfil $p): RegistroComida => tap((new RegistroComida)->forceFill($comun + ['perfil_id' => $p->id]))->save());

        if ($otraCosa && $programada !== null) {
            $this->pedirIntercambio($programada, $comun, $perfiles->pluck('nombre')->all());
        }

        return $registros->first();
    }

    /** Dice al agente qué se comió y qué estaba programado. Va tras confirmar el registro, para que el agente ya lo vea en el historial. */
    private function pedirIntercambio(array $programada, array $comun, array $nombres): void
    {
        $momento = MomentoComida::from($comun['momento'] instanceof MomentoComida ? $comun['momento']->value : $comun['momento']);
        $texto = sprintf(
            '%s comió%s en la %s del %s: %s. Estaba programado: %s.',
            implode(', ', $nombres), count($nombres) > 1 ? 'eron' : '', mb_strtolower($momento->getLabel()),
            CarbonImmutable::parse($comun['fecha'])->format('d/m/Y'), $comun['descripcion'], $programada['titulo'],
        );
        $menuId = $programada['menu']->id;

        DB::afterCommit(function () use ($menuId, $texto): void {
            try {
                $lanzado = Menu::query()->findOrFail($menuId)->pedirIntercambio($texto);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('No se pudo pedir el intercambio al agente')->body($e->getMessage())->warning()->persistent()->send();

                return;
            }
            Notification::make()->title($lanzado ? 'Intercambio en cola' : 'Intercambio pendiente')
                ->body($lanzado ? 'El agente reajusta el menú con lo que se comió; el resultado aparece en el menú activo.'
                    : 'Ya hay un intercambio en curso: este se lanza al terminar.')->success()->send();
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Comida registrada';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
