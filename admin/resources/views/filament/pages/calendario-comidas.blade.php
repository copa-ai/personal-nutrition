{{-- Filament solo compila el CSS de sus propias vistas (no hay tema propio), así que los estilos del calendario se definen aquí. --}}
<x-filament-panels::page>
    @once
    <style>
        .cal { --cal-linea: color-mix(in srgb, currentColor 14%, transparent); --cal-suave: color-mix(in srgb, currentColor 58%, transparent);
               --cal-comido: var(--success-500, #22c55e); --cal-programado: var(--primary-500, #f59e0b); font-size: .875rem; line-height: 1.4;
               display: flex; flex-direction: column; gap: 1rem; }
        .cal *, .cal *::before, .cal *::after { box-sizing: border-box; }
        .cal h2, .cal h3, .cal p, .cal ul { margin: 0; }
        .cal ul { padding: 0; list-style: none; }
        .cal-suave { color: var(--cal-suave); }
        .cal-barra { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1.5rem; }
        .cal-grupo { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .cal-titulo { font-size: 1.125rem; font-weight: 700; margin-left: .5rem; }
        .cal-leyenda { display: flex; flex-wrap: wrap; gap: .25rem 1rem; font-size: .75rem; color: var(--cal-suave); }
        .cal-leyenda span::before { content: ''; display: inline-block; width: .6rem; height: .6rem; border-radius: 999px; margin-right: .35rem; background: var(--tono); }
        .cal-leyenda .comido, .cal-toma--comido { --tono: var(--cal-comido); }
        .cal-leyenda .programado, .cal-toma--programado { --tono: var(--cal-programado); }
        .cal-leyenda .sin_registro, .cal-toma--sin_registro { --tono: var(--cal-suave); }

        .cal-scroll { overflow-x: auto; }
        .cal-rejilla { min-width: 52rem; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-top: 1px solid var(--cal-linea); border-left: 1px solid var(--cal-linea); }
        .cal-cab { padding: .4rem .5rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--cal-suave);
                   border-right: 1px solid var(--cal-linea); border-bottom: 1px solid var(--cal-linea); }
        .cal-dia { display: flex; flex-direction: column; gap: .3rem; min-height: 7rem; padding: .4rem; text-align: left; cursor: pointer; color: inherit; background: transparent; font: inherit;
                   border: 0; border-right: 1px solid var(--cal-linea); border-bottom: 1px solid var(--cal-linea); }
        .cal-dia:hover { background: color-mix(in srgb, currentColor 4%, transparent); }
        .cal-dia--otro-mes { opacity: .5; }
        .cal-dia--elegido { outline: 2px solid var(--cal-programado); outline-offset: -2px; }
        .cal--semana .cal-dia { min-height: 14rem; }
        .cal-num { font-weight: 600; font-variant-numeric: tabular-nums; align-self: flex-start; min-width: 1.5rem; padding: 0 .3rem; text-align: center; border-radius: 999px; }
        .cal-dia--hoy .cal-num { background: var(--cal-programado); color: #fff; }

        .cal-toma { display: flex; flex-direction: column; padding: .15rem .4rem; border-left: 3px solid var(--tono, var(--cal-linea)); border-radius: .25rem;
                    background: color-mix(in srgb, var(--tono, transparent) 10%, transparent); min-width: 0; }
        .cal-toma--sin_registro { background: transparent; border-left-style: dashed; color: var(--cal-suave); }
        .cal-toma--vacia { border-left-color: transparent; background: transparent; color: var(--cal-suave); }
        .cal-et { font-size: .625rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--cal-suave); }
        .cal-txt { overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; }
        .cal--semana .cal-txt { -webkit-line-clamp: 4; }
        .cal-extra { font-size: .75rem; color: var(--cal-suave); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* móvil: selectores a ancho completo, mes con puntos, semana en lista y detalle como hoja casi a pantalla completa */
        .cal-nombre, .cal-punto, .cal-cerrar, .cal-fondo { display: none; }
        .cal-panel { display: block; }
        @media (max-width: 40rem) {
            .cal { font-size: .9375rem; }
            .cal-barra, .cal-barra .cal-grupo { flex-direction: column; align-items: stretch; }
            .cal-barra .cal-grupo:first-child { flex-direction: row; flex-wrap: wrap; align-items: center; }
            .cal-titulo { margin-left: 0; flex-basis: 100%; }
            .cal-barra .cal-grupo > *, .cal-barra .fi-input-wrp, .cal-barra .fi-btn-group { width: 100%; }
            .cal-barra .cal-grupo:first-child > * { width: auto; }
            .cal-barra .fi-btn-group .fi-btn { flex: 1; min-height: 2.75rem; }
            .cal-barra .fi-icon-btn { min-width: 2.75rem; min-height: 2.75rem; }
            .cal-barra select { min-height: 2.75rem; }
            .cal-scroll { overflow-x: visible; }
            .cal-rejilla { min-width: 0; }
            /* mes: celdas cuadradas con puntos de colores en vez del texto */
            .cal--mes .cal-dia { min-height: 3.5rem; align-items: center; padding: .3rem .1rem; gap: .2rem; }
            .cal--mes .cal-cab { padding: .3rem 0; text-align: center; font-size: .625rem; letter-spacing: 0; }
            .cal--mes .cal-num { align-self: center; }
            .cal--mes .cal-toma { display: none; }
            .cal--mes .cal-punto { display: inline-block; width: .5rem; height: .5rem; border-radius: 999px; background: var(--tono, var(--cal-suave)); }
            .cal--mes .cal-puntos { display: flex; gap: .25rem; min-height: .5rem; }
            .cal--mes .cal-extra { display: none; }
            /* semana: una lista vertical */
            .cal--semana .cal-rejilla { display: flex; flex-direction: column; border-left: 0; }
            .cal--semana .cal-cab { display: none; }
            .cal--semana .cal-dia { min-height: 3.5rem; padding: .6rem .5rem; border-right: 0; }
            .cal--semana .cal-nombre { display: inline; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--cal-suave); margin-right: .35rem; }
            .cal--semana .cal-num { align-self: flex-start; }
            .cal--semana .cal-puntos { display: none; }
            .cal--semana .cal-txt { -webkit-line-clamp: 3; }
            .cal--semana .cal-extra { white-space: normal; }
            .cal-dia:focus-visible { outline: 2px solid var(--cal-programado); outline-offset: -2px; }
            /* detalle: hoja a pantalla casi completa con cierre grande */
            .cal-panel { position: fixed; inset: 0; z-index: 40; display: flex; flex-direction: column; justify-content: flex-end; }
            .cal-fondo { display: block; position: absolute; inset: 0; border: 0; padding: 0; background: color-mix(in srgb, #000 55%, transparent); }
            .cal-hoja { position: relative; max-height: 92dvh; overflow-y: auto; overscroll-behavior: contain; border-radius: 1rem 1rem 0 0;
                        padding: 1rem 1rem calc(1rem + env(safe-area-inset-bottom)); background: var(--cal-fondo-hoja, Canvas); color: CanvasText; animation: cal-sube .2s ease-out; }
            :root.dark .cal-hoja, .dark .cal-hoja { --cal-fondo-hoja: rgb(var(--gray-900, 17 24 39)); color: rgb(var(--gray-100, 243 244 246)); }
            .cal-cerrar { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 3rem; margin-top: 1rem; border-radius: .75rem; font: inherit; font-weight: 600; cursor: pointer;
                          color: inherit; background: color-mix(in srgb, currentColor 8%, transparent); border: 1px solid var(--cal-linea); }
            .cal-detalle li { padding: .35rem 0; }
        }
        @keyframes cal-sube { from { transform: translateY(2rem); opacity: 0; } to { transform: none; opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .cal-hoja { animation: none !important; } }
        @media (min-width: 40.0625rem) { .cal-puntos { display: none; } }

        .cal-detalle { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; }
        .cal-detalle h3 { font-weight: 700; margin-bottom: .35rem; }
        .cal-detalle li { padding: .15rem 0; }
        .cal-elegida { font-weight: 600; }
    </style>
    @endonce

    @if ($perfil === null)
        <x-filament::section>
            Todavía no hay ningún hogar con perfiles. Crea un hogar y sus perfiles para ver aquí lo que come cada uno.
        </x-filament::section>
    @else
        <div class="cal cal--{{ $vista }}">
            <div class="cal-barra">
                <div class="cal-grupo">
                    <x-filament::icon-button icon="heroicon-m-chevron-left" label="Anterior" wire:click="mover(-1)" />
                    <x-filament::button color="gray" size="sm" wire:click="irAHoy">Hoy</x-filament::button>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" label="Siguiente" wire:click="mover(1)" />
                    <h2 class="cal-titulo">{{ $titulo }}</h2>
                </div>
                <div class="cal-grupo">
                    @if ($hogares->count() > 1)
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="hogarId" aria-label="Hogar">
                                @foreach ($hogares as $id => $nombre)
                                    <option value="{{ $id }}">{{ $nombre }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="perfilId" aria-label="Perfil">
                            @foreach ($perfiles as $id => $nombre)
                                <option value="{{ $id }}" @selected($id === $perfil->id)>{{ $nombre }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <x-filament::button.group>
                        <x-filament::button size="sm" color="{{ $vista === 'mes' ? 'primary' : 'gray' }}" wire:click="cambiarVista('mes')">Mes</x-filament::button>
                        <x-filament::button size="sm" color="{{ $vista === 'semana' ? 'primary' : 'gray' }}" wire:click="cambiarVista('semana')">Semana</x-filament::button>
                    </x-filament::button.group>
                </div>
            </div>

            <div class="cal-leyenda">
                <span class="comido">Comido</span>
                <span class="programado">Programado en el menú activo</span>
                <span class="sin_registro">Programado y sin registrar</span>
            </div>

            <div class="cal-scroll">
                <div class="cal-rejilla">
                    @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $nombreDia)
                        <div class="cal-cab">{{ $nombreDia }}</div>
                    @endforeach
                    @foreach ($semanas as $semana)
                        @foreach ($semana as $d)
                            <button type="button" wire:key="dia-{{ $d['fecha'] }}" wire:click="elegirDia('{{ $d['fecha'] }}')"
                                aria-label="{{ \Carbon\CarbonImmutable::parse($d['fecha'])->locale('es')->isoFormat('dddd D [de] MMMM') }}"
                                class="cal-dia @if ($vista === 'mes' && $d['mes'] !== $mesActual) cal-dia--otro-mes @endif @if ($d['hoy']) cal-dia--hoy @endif @if ($diaElegido === $d['fecha']) cal-dia--elegido @endif">
                                <span><span class="cal-nombre">{{ \Carbon\CarbonImmutable::parse($d['fecha'])->locale('es')->isoFormat('ddd') }}</span><span class="cal-num">{{ $d['dia'] }}</span></span>
                                <span class="cal-puntos" aria-hidden="true">
                                    @foreach (['comida', 'cena'] as $momentoPunto)
                                        @if ($d['tomas'][$momentoPunto])<span class="cal-punto cal-toma--{{ $d['tomas'][$momentoPunto]['tipo'] }}"></span>@endif
                                    @endforeach
                                </span>
                                @foreach (['comida' => 'Comida', 'cena' => 'Cena'] as $momento => $etiqueta)
                                    @php($toma = $d['tomas'][$momento])
                                    @if ($toma)
                                        <span class="cal-toma cal-toma--{{ $toma['tipo'] }}" title="{{ $toma['titulo'] }}">
                                            <span class="cal-et">{{ $etiqueta }}</span>
                                            <span class="cal-txt">{{ $toma['titulo'] }}</span>
                                        </span>
                                    @endif
                                @endforeach
                                @if ($vista === 'semana')
                                    @foreach ($d['individuales'] as $ind)
                                        <span class="cal-extra" title="{{ $ind['titulo'] }}">{{ $ind['etiqueta'] }}: {{ $ind['titulo'] }}</span>
                                    @endforeach
                                @endif
                                @if ($d['otros'] !== [])
                                    <span class="cal-extra">+ {{ count($d['otros']) }} más anotada{{ count($d['otros']) > 1 ? 's' : '' }}</span>
                                @endif
                            </button>
                        @endforeach
                    @endforeach
                </div>
            </div>

            @if ($detalle)
                <div class="cal-panel" role="dialog" aria-label="{{ $detalle['titulo'] }}">
                <button type="button" class="cal-fondo" wire:click="elegirDia('')" tabindex="-1" aria-label="Cerrar el detalle"></button>
                <div class="cal-hoja">
                <x-filament::section :heading="$detalle['titulo']" :description="'Perfil: '.$perfil->nombre">
                    <div class="cal-detalle">
                        @foreach (['comida' => 'Comida', 'cena' => 'Cena'] as $momento => $etiqueta)
                            @php($toma = $detalle['tomas'][$momento])
                            <div>
                                <h3>{{ $etiqueta }}</h3>
                                @if ($toma === null)
                                    <p class="cal-suave">Sin nada anotado ni programado.</p>
                                @elseif ($toma['tipo'] === 'comido')
                                    <p>{{ $toma['titulo'] }}</p>
                                    <p class="cal-suave">Comido · {{ $toma['origen'] }}</p>
                                @else
                                    <p class="cal-suave">{{ $toma['tipo'] === 'programado' ? 'Programado en el menú activo' : 'Estaba programado y no se anotó lo comido' }}</p>
                                    <ul>
                                        @foreach ($toma['opciones'] as $i => $opcion)
                                            <li @class(['cal-elegida' => $i === $toma['elegida']])>{{ $opcion }}@if ($i === $toma['elegida']) <span class="cal-suave">(elegida)</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                        @if ($detalle['individuales'] !== [])
                            <div>
                                <h3>Resto de tomas</h3>
                                <ul>
                                    @foreach ($detalle['individuales'] as $ind)
                                        <li><span class="cal-suave">{{ $ind['etiqueta'] }}:</span> {{ $ind['titulo'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($detalle['otros'] !== [])
                            <div>
                                <h3>Otras tomas anotadas</h3>
                                <ul>
                                    @foreach ($detalle['otros'] as $otro)
                                        <li>{{ $otro['titulo'] }} <span class="cal-suave">· {{ $otro['origen'] }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                    <button type="button" class="cal-cerrar" wire:click="elegirDia('')">Cerrar</button>
                </x-filament::section>
                </div>
                </div>
            @else
                <p class="cal-suave">Pulsa un día para ver su detalle.</p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
