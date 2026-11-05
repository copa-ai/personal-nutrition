<?php

namespace App\Filament\Pages;

use App\Filament\Support\DatosCalendario;
use App\Models\Hogar;
use App\Models\Perfil;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/** Calendario de un perfil: lo que ha comido en los días pasados y lo que le toca en los próximos, por mes o por semana. */
class CalendarioComidas extends Page
{
    protected string $view = 'filament.pages.calendario-comidas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|\UnitEnum|null $navigationGroup = 'Seguimiento';

    protected static ?string $navigationLabel = 'Calendario';

    protected static ?string $title = 'Calendario';

    protected static ?string $slug = 'calendario';

    protected static ?int $navigationSort = 0;

    /** «mes» o «semana». */
    public string $vista = 'mes';

    /** Un día cualquiera del mes o de la semana que se está viendo (Y-m-d). */
    public string $ancla = '';

    public ?string $hogarId = null;

    public ?string $perfilId = null;

    /** Día del que se enseña el detalle (Y-m-d). */
    public ?string $diaElegido = null;

    public function mount(): void
    {
        $this->ancla = $this->hoy()->toDateString();
        $this->hogarId = Hogar::query()->orderBy('nombre')->value('id');
    }

    /** Otro hogar tiene otros perfiles: se empieza por el primero. */
    public function updatedHogarId(): void
    {
        $this->perfilId = null;
        $this->diaElegido = null;
    }

    public function cambiarVista(string $vista): void
    {
        if (in_array($vista, ['mes', 'semana'], true)) {
            $this->vista = $vista;
        }
    }

    /** Avanza (o retrocede, con negativo) un mes o una semana según la vista. */
    public function mover(int $pasos): void
    {
        $ancla = $this->fechaAncla();
        $this->ancla = ($this->vista === 'mes' ? $ancla->addMonthsNoOverflow($pasos) : $ancla->addWeeks($pasos))->toDateString();
    }

    public function irAHoy(): void
    {
        $this->ancla = $this->hoy()->toDateString();
        $this->diaElegido = $this->ancla;
    }

    public function elegirDia(string $fecha): void
    {
        $this->diaElegido = $this->parsear($fecha)?->toDateString();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $hogares = Hogar::query()->orderBy('nombre')->pluck('nombre', 'id');
        $hogar = (Str::isUuid((string) $this->hogarId) ? Hogar::query()->find($this->hogarId) : null) ?? Hogar::query()->orderBy('nombre')->first();
        $perfiles = $hogar ? Perfil::query()->where('hogar_id', $hogar->id)->orderBy('nombre')->pluck('nombre', 'id') : collect();
        $perfil = $hogar ? (Str::isUuid((string) $this->perfilId) ? Perfil::query()->where('hogar_id', $hogar->id)->find($this->perfilId) : null) ?? Perfil::query()->where('hogar_id', $hogar->id)->orderBy('nombre')->first() : null;

        $datos = ['hogares' => $hogares, 'perfiles' => $perfiles, 'perfil' => $perfil, 'hogar' => $hogar, 'vista' => $this->vista];
        if ($perfil === null) {
            return $datos;
        }

        $ancla = $this->fechaAncla();
        [$desde, $hasta] = $this->vista === 'mes'
            ? [$ancla->startOfMonth()->startOfWeek(CarbonInterface::MONDAY), $ancla->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()]
            : [$ancla->startOfWeek(CarbonInterface::MONDAY), $ancla->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()];
        $calendario = new DatosCalendario($hogar, $perfil, $this->hoy());
        $elegido = $this->parsear((string) $this->diaElegido);

        return $datos + [
            'titulo' => $this->vista === 'mes'
                ? ucfirst($ancla->locale('es')->isoFormat('MMMM YYYY'))
                : 'Del '.$desde->locale('es')->isoFormat('D [de] MMMM').' al '.$hasta->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'mesActual' => $ancla->month,
            'semanas' => array_chunk($calendario->dias($desde, $hasta), 7),
            'detalle' => $elegido ? $calendario->dias($elegido, $elegido)[0] + ['titulo' => ucfirst($elegido->locale('es')->isoFormat('dddd D [de] MMMM'))] : null,
        ];
    }

    private function hoy(): CarbonImmutable
    {
        return now()->startOfDay()->toImmutable();
    }

    private function fechaAncla(): CarbonImmutable
    {
        return $this->parsear($this->ancla) ?? $this->hoy();
    }

    /** Las fechas llegan del navegador: una que no se entienda se ignora. */
    private function parsear(string $fecha): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $fecha) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
