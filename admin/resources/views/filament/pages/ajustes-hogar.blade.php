<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="Tiempos de cocinado">
        Los minutos que hay para cocinar cada toma cada día de la semana están en
        <a class="underline" href="{{ \App\Filament\Resources\Tiempos\TiempoResource::getUrl() }}">Tiempos de cocinado</a>.
        Sin ellos el agente no puede planificar el menú.
    </x-filament::section>
</x-filament-panels::page>
