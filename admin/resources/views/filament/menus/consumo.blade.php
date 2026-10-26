@php
    use App\Filament\Support\PestanaConsumo;
    use App\Services\Menu\ResumenConsumo;

    /** @var \App\Models\Menu $record */
    $record = $getRecord();
    $r = ResumenConsumo::de($record);
    $c = $r['costes'];
    $t = $r['totales'];
    $fases = collect($r['por_fase'])->sortBy(fn (array $f): int => PestanaConsumo::ordenFase($f['fase']))->values();
    $totalFases = max(0.000001, (float) $fases->sum(fn (array $f): float => (float) ($f['coste'] ?? 0)));
    $numero = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
@include('filament.menus._estilos')

@if ($t['respuestas'] === 0)
    <div class="mn mn-vacio">
        <x-filament::icon icon="heroicon-o-cpu-chip" class="mn-icono" />
        <p><strong>Todavía no hay consumo registrado.</strong></p>
        <p>Aparece cuando el agente responde por primera vez, y se cuenta respuesta a respuesta.</p>
    </div>
@else
    <div class="mn mn-consumo">

        @if ($r['sin_tarifa'] > 0)
            <div class="mn-alerta mn-alerta--aviso" role="status">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mn-icono" style="color: var(--info-500, #3b82f6)" />
                <p>{{ $r['sin_tarifa'] }} {{ $r['sin_tarifa'] === 1 ? 'respuesta no tiene' : 'respuestas no tienen' }} tarifa para su modelo y no cuenta{{ $r['sin_tarifa'] === 1 ? '' : 'n' }} en el coste.</p>
            </div>
        @endif

        <div class="mn-total">
            <div class="mn-kpi mn-kpi--grande">
                <span class="mn-etiqueta">Coste total</span>
                <span class="valor">{{ PestanaConsumo::dinero($c['total'] ?? null) }}</span>
                <span class="nota">{{ $t['respuestas'] }} {{ $t['respuestas'] === 1 ? 'respuesta' : 'respuestas' }} del modelo</span>
            </div>
            <div class="mn-kpi"><span class="mn-etiqueta">Entrada</span><span class="valor" style="font-size:1.2rem">{{ PestanaConsumo::dinero($c['entrada'] ?? null) }}</span><span class="nota">{{ $numero($t['input_tokens']) }} tokens</span></div>
            <div class="mn-kpi"><span class="mn-etiqueta">Salida</span><span class="valor" style="font-size:1.2rem">{{ PestanaConsumo::dinero($c['salida'] ?? null) }}</span><span class="nota">{{ $numero($t['output_tokens']) }} tokens</span></div>
            <div class="mn-kpi"><span class="mn-etiqueta">Caché</span><span class="valor" style="font-size:1.2rem">{{ PestanaConsumo::dinero($c['cache'] ?? null) }}</span><span class="nota">{{ $numero($t['cache_read'] + $t['cache_creation']) }} tokens</span></div>
            @if (($t['web_search'] ?? 0) > 0)
                <div class="mn-kpi"><span class="mn-etiqueta">Búsquedas web</span><span class="valor" style="font-size:1.2rem">{{ PestanaConsumo::dinero($c['web'] ?? null) }}</span><span class="nota">{{ $t['web_search'] }} búsquedas</span></div>
            @endif
        </div>

        <div class="mn-bloque">
            <span class="mn-etiqueta">Por fase del trabajo</span>
            <table class="mn-tabla">
                <thead><tr><th>Fase</th><th class="num">Respuestas</th><th class="num">Tokens</th><th class="num">Coste</th><th>Reparto</th></tr></thead>
                <tbody>
                    @foreach ($fases as $f)
                        @php($parte = $f['coste'] === null ? 0 : (int) round(($f['coste'] / $totalFases) * 100))
                        <tr>
                            <td class="fase">{{ PestanaConsumo::etiquetaFase($f['fase']) }}</td>
                            <td class="num">{{ $f['respuestas'] }}</td>
                            <td class="num">{{ $numero(PestanaConsumo::sumaTokens($f)) }}</td>
                            <td class="num">{{ PestanaConsumo::dinero($f['coste']) }}</td>
                            <td><div class="mn-cuota"><div class="barra"><span style="width: {{ $parte }}%"></span></div><span class="mn-suave" style="font-size:.75rem">{{ $parte }} %</span></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($r['congelado'])
            <div class="mn-bloque">
                <span class="mn-etiqueta">Por modelo y tarifa</span>
                <table class="mn-tabla">
                    <thead><tr><th>Modelo</th><th class="num">Respuestas</th><th class="num">Tokens</th><th>Tarifa aplicada (USD por millón)</th><th class="num">Coste</th></tr></thead>
                    <tbody>
                        @foreach ($r['por_modelo'] as $m)
                            <tr>
                                <td class="fase">{{ $m['modelo'] }}</td>
                                <td class="num">{{ $m['respuestas'] }}</td>
                                <td class="num">{{ $numero(PestanaConsumo::sumaTokens($m)) }}</td>
                                <td>
                                    @forelse ($m['tarifas'] as $p)
                                        <div>entrada {{ $p['entrada'] ?? '?' }} · salida {{ $p['salida'] ?? '?' }} <span class="mn-suave">({{ $p['fecha'] ?? '' }})</span></div>
                                    @empty
                                        <span class="mn-suave">sin tarifa</span>
                                    @endforelse
                                </td>
                                <td class="num">{{ PestanaConsumo::dinero($m['coste']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <details class="mn-detalles">
            <summary>Detalle de tokens</summary>
            <dl>
                <div><dt>Tokens totales</dt><dd>{{ $numero(PestanaConsumo::sumaTokens($t)) }}</dd></div>
                <div><dt>Entrada</dt><dd>{{ $numero($t['input_tokens']) }}</dd></div>
                <div><dt>Salida</dt><dd>{{ $numero($t['output_tokens']) }}</dd></div>
                <div><dt>Razonamiento (dentro de la salida)</dt><dd>{{ $numero($t['thinking_tokens']) }}</dd></div>
                <div><dt>Caché leída</dt><dd>{{ $numero($t['cache_read']) }}</dd></div>
                <div><dt>Caché creada</dt><dd>{{ $numero($t['cache_creation']) }}</dd></div>
                <div><dt>Búsquedas web</dt><dd>{{ $numero($t['web_search']) }}</dd></div>
                <div><dt>Lecturas web</dt><dd>{{ $numero($t['web_fetch']) }}</dd></div>
            </dl>
        </details>

        <p class="mn-suave" style="font-size:.75rem">
            @if ($r['congelado'])
                Cada respuesta se valoró al registrarla, con la tarifa de ese momento y el modelo que respondió: cambiar los precios después no altera este histórico.
            @else
                Estimado con las tarifas actuales de {{ config('agente.modelo') }} (menú anterior al registro de costes).
            @endif
            Con una suscripción, el coste en dólares es solo una referencia: lo que de verdad se gasta es el límite de uso del plan.
        </p>
    </div>
@endif
