<x-filament-panels::page>
    @if ($menu === null)
        <x-filament::section heading="Sin menú activo">
            El hogar no tiene un menú activo, así que no hay lista de la compra.
        </x-filament::section>
    @elseif ($grupos === [])
        <x-filament::section heading="Nada que comprar">
            La despensa cubre todo lo que pide el menú «{{ $menu }}».
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500">
            Lo que falta para el menú «{{ $menu }}» tras restar la despensa. Para marcarlo como comprado, usa «Lista de la compra» desde el chat de la app.
        </p>
        @foreach ($grupos as $pasillo => $filas)
            <x-filament::section :heading="$pasillo">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($filas as $fila)
                        <li class="flex items-baseline justify-between gap-3 py-1.5">
                            <span>{{ $fila['nombre'] }}</span>
                            <span class="text-sm text-gray-500">{{ $fila['falta'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
