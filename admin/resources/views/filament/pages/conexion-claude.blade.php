<x-filament-panels::page>
    <x-filament::section>
        @if ($configurado)
            La conexión con Claude está configurada. Se usa al generar cada menú.
        @else
            Todavía no hay conexión con Claude configurada. Sin ella no se podrán generar menús.
        @endif
    </x-filament::section>
    <x-filament::section heading="Cómo configurar la conexión">
        <ol class="list-decimal pl-5 space-y-1">
            <li>Pide al equipo técnico la clave de conexión con Claude.</li>
            <li>Pulsa «Pegar clave» arriba y pégala.</li>
            <li>Después, pulsa «Probar conexión» para comprobar que funciona.</li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
