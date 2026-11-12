@php
    use App\Enums\CategoriaComida;
    use App\Enums\EstadoMenu;

    /** @var \App\Models\Menu $record */
    $record = $getRecord();
    // El interruptor «Menú vigente / por defecto» de la ficha: por defecto se ve el menú tal como lo generó el agente.
    $porDefecto = (bool) ($getLivewire()->verPorDefecto ?? false);
    $hayCambios = $record->tomasCambiadas() !== [];
    $record->conCambios(! $porDefecto);
    $dias = $record->tomas();
    $perfiles = collect($record->encargo['perfiles'] ?? []);
    $nombres = $perfiles->pluck('nombre', 'id');
    $explicaciones = $record->explicaciones();
    // Las elecciones se guardan sobre las tomas vigentes: en la vista «por defecto» no se pueden cambiar.
    $puedeElegir = ! $record->estado->enCurso() && ! $porDefecto;
    $cantidad = fn ($n, $unidad) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',').($unidad !== '' ? ' '.$unidad : '');
@endphp
@include('filament.menus._estilos')

@if (! $dias)
    <div class="mn mn-vacio">
        @if ($record->estado->enCurso())
            <x-filament::icon icon="heroicon-o-arrow-path" class="mn-icono" />
            <p><strong>El agente está preparando el menú.</strong></p>
            <p>Puedes seguir el avance en la pestaña «Registro»; esta pantalla se actualiza sola.</p>
        @elseif ($record->estado === EstadoMenu::Fallido)
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mn-icono" />
            <p><strong>Esta vez no se pudo generar el menú.</strong></p>
            <p>El motivo está arriba. Pulsa «Generar menú» para volver a intentarlo.</p>
        @else
            <x-filament::icon icon="heroicon-o-calendar-days" class="mn-icono" />
            <p><strong>Todavía no hay menú.</strong></p>
            <p>Pulsa «Generar menú» y el agente propondrá comida y cena para toda la semana.</p>
        @endif
    </div>
@else
    <div class="mn mn-menu">

        <div class="mn-perfiles" aria-label="Personas del menú">
            @foreach ($perfiles as $p)
                <span class="mn-perfil">
                    <span class="mn-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($p['nombre'], 0, 1)) }}</span>
                    <span><strong>{{ $p['nombre'] }}</strong>
                        <span class="mn-suave">
                            @if (! empty($p['kcal_diarias'])) · {{ number_format($p['kcal_diarias'], 0, ',', '.') }} kcal @endif
                            @if (! empty($p['restricciones'])) · {{ implode(', ', $p['restricciones']) }} @endif
                        </span>
                    </span>
                </span>
            @endforeach
        </div>

        @if ($hayCambios)
            <div class="mn-ayuda">
                @if ($porDefecto)
                    <span><strong>Estás viendo el menú por defecto</strong>, tal como lo generó el agente. Pulsa «Ver menú vigente» para volver a lo que se sigue ahora.</span>
                @else
                    <span><strong>Menú vigente:</strong> {{ count($record->tomasCambiadas()) }} {{ count($record->tomasCambiadas()) === 1 ? 'toma cambiada' : 'tomas cambiadas' }} por lo comido. Las tomas con la marca «Cambiada» ya no son las del menú original.</span>
                @endif
            </div>
        @endif

        @if ($explicaciones)
            <div class="mn-bloque mn-explicaciones">
                <span class="mn-etiqueta">Cómo se ha pensado el menú</span>
                @foreach ($explicaciones as $e)
                    <div class="mn-persona-fila">
                        <span class="quien">{{ $e['nombre'] }}</span>
                        <span>{{ $e['texto'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($puedeElegir)
            <div class="mn-ayuda">
                <span><strong>Elige una opción por toma.</strong> El PDF y la lista de la compra se hacen con lo que elijas.</span>
                <span class="mn-suave"><strong>{{ $record->totalElegidas() }} de {{ $record->totalTomas() }}</strong> con elección propia · el resto usa la primera opción</span>
            </div>
        @endif

        <nav class="mn-nav" aria-label="Ir a un día" data-nav>
            @foreach ($dias as $d)
                @php($hechas = collect($d['tomas'])->filter(fn ($t) => $t['hay_eleccion'])->count())
                <a class="mn-pill" href="#dia-{{ $d['fecha'] }}">{{ $d['corto'] }}@if ($hechas > 0) <small>{{ $hechas }}/{{ collect($d['tomas'])->filter(fn ($t) => $t['opciones'])->count() }}</small>@endif</a>
            @endforeach
        </nav>

        @foreach ($dias as $d)
            @php($cocinaHoy = collect($d['tomas'])->filter(fn ($t) => $t['opciones'])->count())
            <section class="mn-dia" id="dia-{{ $d['fecha'] }}" aria-label="{{ $d['titulo'] }}">
                <div class="mn-dia-cab">
                    <h3>{{ $d['titulo'] }}</h3>
                    <span class="mn-suave">{{ $cocinaHoy === 0 ? 'No se cocina' : collect($d['tomas'])->filter(fn ($t) => $t['hay_eleccion'])->count().' de '.$cocinaHoy.' elegidas' }}</span>
                </div>

                <div class="mn-tomas">
                    @foreach ($d['tomas'] as $momento => $t)
                        <div class="mn-toma mn-toma--{{ $momento }}">
                            <div class="mn-toma-cab">
                                <x-filament::icon :icon="$momento === 'comida' ? 'heroicon-o-sun' : 'heroicon-o-moon'" class="mn-icono" />
                                <span class="nombre">{{ $t['etiqueta'] }}</span>
                                @if ($t['cambiada'])<span class="mn-chip" style="color:var(--warning-600);border-color:var(--warning-600)" title="Esta toma se ha cambiado respecto al menú original">Cambiada</span>@endif
                                @if ($t['minutos'] !== null)<span class="disp">{{ $t['minutos'] }} min disponibles</span>@endif
                            </div>

                            @if ($t['categorias'])
                                <div class="mn-op-chips" aria-label="Categorías de la toma">
                                    @foreach ($t['categorias'] as $c)
                                        <span class="mn-chip">{{ CategoriaComida::tryFrom($c)?->getLabel() ?? $c }}</span>
                                    @endforeach
                                </div>
                            @endif

                            @if (! $t['opciones'])
                                <p class="mn-nocina">No se cocina {{ $momento === 'comida' ? 'a mediodía' : 'por la noche' }}.</p>
                            @else
                                <div role="radiogroup" aria-label="Opciones de {{ mb_strtolower($t['etiqueta']) }} del {{ mb_strtolower($d['titulo']) }}">
                                    @foreach ($t['opciones'] as $i => $o)
                                        @php($elegida = $t['elegida'] === $i)
                                        <div class="mn-op {{ $elegida ? 'mn-op--elegida' : '' }}">
                                            <button type="button" class="mn-op-fila" role="radio" aria-checked="{{ $elegida ? 'true' : 'false' }}"
                                                @if ($puedeElegir) wire:click="elegirOpcion(@js($d['fecha']), @js($momento), {{ $i }})" wire:loading.attr="disabled" @else disabled @endif>
                                                <span class="mn-radio" aria-hidden="true"></span>
                                                <span class="mn-op-titulo">{{ $o['titulo'] }}</span>
                                                @if ($elegida)
                                                    <span class="mn-etiqueta-eleccion">{{ $t['hay_eleccion'] ? 'Tu elección' : 'Por defecto' }}</span>
                                                @else
                                                    <span class="mn-op-meta">{{ $o['duracion_momento_min'] }} min · {{ $o['por_comprar'] === 0 ? 'todo en casa' : $o['por_comprar'].' por comprar' }}</span>
                                                @endif
                                            </button>

                                            @unless ($elegida)
                                                <div class="mn-op-chips">
                                                    @foreach ($o['categorias'] ?? [] as $c)
                                                        <span class="mn-chip">{{ CategoriaComida::tryFrom($c)?->getLabel() ?? $c }}</span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="mn-detalle">
                                                    <div style="display:flex;flex-wrap:wrap;gap:.25rem">
                                                        <span class="mn-chip">{{ $o['duracion_momento_min'] }} min al momento</span>
                                                        @if (($o['duracion_previa_min'] ?? 0) > 0)<span class="mn-chip">Preparar antes: {{ $o['duracion_previa_min'] }} min</span>@endif
                                                        @foreach ($o['categorias'] ?? [] as $c)
                                                            <span class="mn-chip">{{ CategoriaComida::tryFrom($c)?->getLabel() ?? $c }}</span>
                                                        @endforeach
                                                    </div>

                                                    @if (! empty($o['ingredientes']))
                                                        <div class="mn-bloque">
                                                            <span class="mn-etiqueta">Ingredientes · {{ $o['por_comprar'] === 0 ? 'todo en casa' : $o['por_comprar'].' por comprar' }}</span>
                                                            <ul class="mn-ing">
                                                                @foreach ($o['ingredientes'] as $ing)
                                                                    @php($enCasa = ($ing['en_stock'] ?? false) === true)
                                                                    <li class="{{ $enCasa ? 'encasa' : 'comprar' }}" title="{{ $enCasa ? 'Ya está en casa' : 'Hay que comprarlo' }}">
                                                                        <span class="marca" aria-hidden="true">{{ $enCasa ? '✓' : '+' }}</span>
                                                                        <span>
                                                                            @if (isset($ing['cantidad'])) {{ $cantidad($ing['cantidad'], (string) ($ing['unidad'] ?? '')) }} @endif
                                                                            {{ $ing['nombre'] }}
                                                                            <span class="mn-sr">{{ $enCasa ? '(ya en casa)' : '(por comprar)' }}</span>
                                                                        </span>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if (! empty($o['ajustes']))
                                                        <div class="mn-bloque">
                                                            <span class="mn-etiqueta">Ajustes por persona</span>
                                                            @foreach ($o['ajustes'] as $a)
                                                                <div class="mn-persona-fila">
                                                                    <span class="quien">{{ $nombres[$a['perfil_id']] ?? 'Perfil '.$a['perfil_id'] }}</span>
                                                                    <span>{{ $a['texto'] }}</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif

                                                    @if (! empty($o['pasos']))
                                                        <div class="mn-bloque">
                                                            <span class="mn-etiqueta">Cómo se hace</span>
                                                            <ol class="mn-pasos">@foreach ($o['pasos'] as $paso)<li>{{ $paso }}</li>@endforeach</ol>
                                                        </div>
                                                    @endif
                                                </div>
                                            @endunless
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($d['individuales'])
                    <div class="mn-bloque mn-individuales">
                        <span class="mn-etiqueta">Desayuno, almuerzo y merienda por persona</span>
                        @foreach ($d['individuales'] as $ind)
                            <div class="mn-persona-fila">
                                <span class="quien">{{ $ind['nombre'] }}</span>
                                <span class="mn-ind-tomas">
                                    @foreach ($ind['tomas'] as $x)
                                        @php($tm = $x['toma'])
                                        <span class="mn-ind-toma">
                                            <strong>{{ $x['etiqueta'] }}</strong>: {{ $tm['titulo'] ?? '' }}
                                            <span class="mn-suave">· {{ $tm['duracion_momento_min'] ?? 0 }} min{{ ($tm['duracion_previa_min'] ?? 0) > 0 ? ', '.$tm['duracion_previa_min'].' antes' : '' }}</span>
                                            @if (! empty($tm['descripcion']))<span class="mn-suave"> {{ $tm['descripcion'] }}</span>@endif
                                            @if (! empty($tm['ingredientes']))
                                                <span class="mn-suave">({{ collect($tm['ingredientes'])->map(fn ($i) => (isset($i['cantidad']) ? $cantidad($i['cantidad'], (string) ($i['unidad'] ?? '')).' ' : '').$i['nombre'])->implode(', ') }})</span>
                                            @endif
                                            @if (! empty($tm['nota']))<em class="mn-suave"> {{ $tm['nota'] }}</em>@endif
                                        </span>
                                    @endforeach
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endif
