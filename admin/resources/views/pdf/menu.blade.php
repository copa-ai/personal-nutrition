@php
    use App\Enums\CategoriaComida;

    /** @var array<string, mixed> $d */
    $nombres = collect($d['perfiles'])->pluck('nombre', 'id');
    $columnas = 3;
    $porColumna = max(1, (int) ceil(count($d['compra']['comprar']) / $columnas));
    $cantidad = function (?float $n, string $unidad): string {
        if ($n === null) {
            return $unidad !== '' ? $unidad : '—';
        }

        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',').($unidad !== '' ? ' '.$unidad : '');
    };
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $d['titulo'] }}</title>
<style>
    @page { margin: 34pt 34pt 44pt 34pt; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; line-height: 1.42; color: #2A333F; margin: 0; }
    h1, h2, h3 { font-family: 'DejaVu Serif', serif; font-weight: normal; color: #141C26; margin: 0; }
    p { margin: 0; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }

    /* cabecera */
    .eyebrow { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; letter-spacing: 1.4pt; text-transform: uppercase; color: #1D4E89; }
    h1 { font-size: 19pt; line-height: 1.05; margin-top: 6pt; }
    h1 em { display: block; font-size: 17pt; font-style: italic; color: #1D4E89; margin-top: 3pt; }
    .entradilla { font-size: 9.5pt; margin-top: 12pt; width: 85%; }
    .supuestos { margin-top: 12pt; }
    .supuesto { display: inline-block; font-family: 'DejaVu Sans Mono', monospace; font-size: 6.8pt; color: #5D6874; border: 1px solid #D9D8D0; padding: 2pt 6pt; margin: 0 4pt 4pt 0; }
    .cabecera { padding-bottom: 12pt; border-bottom: 1px solid #D9D8D0; }

    /* rótulos de sección */
    .rotulo { width: 100%; border-collapse: collapse; border-bottom: 2px solid #141C26; margin-top: 20pt; }
    .rotulo td { padding: 0 0 5pt 0; vertical-align: bottom; }
    .rotulo h2 { font-size: 19pt; }
    .rotulo .aparte { text-align: right; font-family: 'DejaVu Sans Mono', monospace; font-size: 6.8pt; letter-spacing: 0.6pt; text-transform: uppercase; color: #5D6874; }

    /* la semana */
    .dia { width: 100%; border-collapse: collapse; table-layout: fixed; border-bottom: 1px solid #E9E7E0; page-break-inside: avoid; }
    .dia td { vertical-align: top; }
    .fecha { width: 58pt; padding: 9pt 8pt 9pt 0; }
    .azulejo { width: 26pt; height: 26pt; line-height: 21pt; text-align: center; border: 1px solid #1D4E89; background: #EDF1F7; font-family: 'DejaVu Serif', serif; font-size: 14pt; color: #1D4E89; }
    .dnombre { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.3pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #5D6874; margin-top: 5pt; }
    .toma { padding: 9pt 10pt; border-left: 1px solid #E9E7E0; }
    .toma.cena { background: #F0F2E7; }
    .cab { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.8pt; letter-spacing: 1pt; text-transform: uppercase; margin-bottom: 4pt; }
    .comida .cab { color: #1D4E89; }
    .cena .cab { color: #5F6E38; }
    .cab .reloj { float: right; letter-spacing: 0.3pt; color: #5D6874; }
    .toma h3 { font-size: 12pt; line-height: 1.12; margin-bottom: 3pt; }
    .chip { display: inline-block; font-family: 'DejaVu Sans Mono', monospace; font-size: 5.8pt; letter-spacing: 0.4pt; text-transform: uppercase; color: #5D6874; border: 1px solid #D9D8D0; border-radius: 8pt; padding: 1pt 5pt; margin: 0 2pt 2pt 0; }
    .chip.destaca { color: #A63D22; border-color: #A63D22; }
    .pasos { margin: 4pt 0 0 0; padding-left: 11pt; font-size: 7.5pt; line-height: 1.32; }
    .pasos li { margin-bottom: 1pt; }
    .ing { font-size: 7.2pt; color: #5D6874; margin-top: 4pt; }
    .ing b { font-weight: normal; font-family: 'DejaVu Sans Mono', monospace; font-size: 6pt; letter-spacing: 0.8pt; text-transform: uppercase; color: #5F6E38; }
    .indiv td { border-top: 1px solid #E9E7E0; padding: 5pt 10pt 6pt 10pt; font-size: 7.4pt; line-height: 1.3; vertical-align: top; }
    .indiv .quien { font-weight: bold; color: #141C26; }
    .indiv .m { font-family: 'DejaVu Sans Mono', monospace; font-size: 5.8pt; letter-spacing: 0.8pt; text-transform: uppercase; color: #5F6E38; }
    .indiv .t { color: #141C26; }
    .indiv .mins { font-family: 'DejaVu Sans Mono', monospace; font-size: 6pt; color: #5D6874; }
    .indiv .sub { color: #5D6874; font-size: 6.8pt; margin-top: 1pt; }
    .indiv .nota { color: #A63D22; font-size: 6.8pt; font-style: italic; margin-top: 1pt; }
    .nada { font-size: 8pt; color: #5D6874; font-style: italic; }

    /* la compra */
    .compra { width: 100%; border-collapse: collapse; margin-top: 14pt; table-layout: fixed; }
    .compra td { vertical-align: top; padding-right: 18pt; }
    .it { font-size: 8.4pt; border-bottom: 1px dotted #D9D8D0; padding: 3pt 0; overflow: hidden; }
    .it .cant { float: right; font-family: 'DejaVu Sans Mono', monospace; font-size: 7.2pt; color: #5D6874; }
    .apunte { font-size: 7.6pt; color: #5D6874; margin-top: 10pt; }
    .vacio { font-size: 8.4pt; color: #5D6874; margin-top: 12pt; font-style: italic; }

    /* personas */
    .personas { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 12pt; }
    .persona { page-break-inside: avoid; border: 1px solid #E9E7E0; padding: 9pt 11pt; margin-bottom: 7pt; }
    .persona h3 { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: 9.4pt; }
    .persona p { font-size: 8pt; margin-top: 3pt; }
    .persona .explica { font-size: 8.4pt; line-height: 1.4; margin-top: 5pt; padding-left: 6pt; border-left: 2px solid #1D4E89; }
    .persona .dato { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; color: #5D6874; }
    .persona .restr { color: #A63D22; }

    .pie { margin-top: 26pt; padding-top: 10pt; border-top: 1px solid #D9D8D0; font-size: 7.4pt; color: #5D6874; width: 90%; }
</style>
</head>
<body>

<div class="cabecera">
    <p class="eyebrow">Menú semanal · {{ $d['hogar'] }}</p>
    <h1>{{ $d['titulo'] }}</h1>
    <p class="entradilla">
        Primero la lista de la compra de todo lo elegido, luego cómo se ha pensado el menú para {{ \Illuminate\Support\Arr::join($nombres->all(), ', ', ' y ') ?: 'el hogar' }}
        y, por último, cada día: la comida y la cena elegidas y, debajo, el desayuno, el almuerzo y la merienda de cada persona.
    </p>
    <div class="supuestos">
        <span class="supuesto">{{ count($d['perfiles']) }} {{ count($d['perfiles']) === 1 ? 'persona' : 'personas' }}</span>
        <span class="supuesto">{{ $d['tomas'] }} comidas y cenas</span>
        @foreach ($d['perfiles'] as $p)
            <span class="supuesto">{{ $p['nombre'] }}@if (! empty($p['kcal_diarias'])) · {{ number_format($p['kcal_diarias'], 0, ',', '.') }} kcal @endif</span>
        @endforeach
        <span class="supuesto">Una opción por toma</span>
    </div>
</div>

<table class="rotulo"><tr><td><h2>La compra</h2></td><td class="aparte">Lo que hay que comprar para lo elegido</td></tr></table>

@if (count($d['compra']['comprar']))
    <table class="compra">
        <tr>
            @foreach (array_chunk($d['compra']['comprar'], $porColumna) as $trozo)
                <td>
                    @foreach ($trozo as $i)
                        <div class="it"><span class="cant">{{ $cantidad($i['cantidad'], $i['unidad']) }}</span>{{ $i['nombre'] }}</div>
                    @endforeach
                </td>
            @endforeach
            @for ($r = count(array_chunk($d['compra']['comprar'], $porColumna)); $r < $columnas; $r++)<td></td>@endfor
        </tr>
    </table>
@else
    <p class="vacio">No hace falta comprar nada: todo lo elegido está ya en casa.</p>
@endif
@if (count($d['compra']['en_casa']))
    <p class="apunte"><b>Ya en casa, no hace falta comprarlo:</b> {{ collect($d['compra']['en_casa'])->map(fn ($i) => $i['nombre'].' ('.$cantidad($i['cantidad'], $i['unidad']).')')->implode(' · ') }}.</p>
@endif

<table class="rotulo"><tr><td><h2>Cada persona</h2></td><td class="aparte">Cómo se ha pensado su menú</td></tr></table>

<div class="personas">
    @foreach ($d['perfiles'] as $p)
        <div class="persona">
            <h3>{{ $p['nombre'] }}</h3>
            @if (! empty($p['objetivo']))<p>{{ $p['objetivo'] }}</p>@endif
            <p class="dato">
                @if (! empty($p['kcal_diarias'])){{ number_format($p['kcal_diarias'], 0, ',', '.') }} kcal/día @endif
                @if (! empty($p['macros_pct']['proteina'])) · P {{ $p['macros_pct']['proteina'] }} % · C {{ $p['macros_pct']['carbohidratos'] }} % · G {{ $p['macros_pct']['grasa'] }} % @endif
            </p>
            @php($explica = collect($d['explicaciones'])->firstWhere('perfil_id', $p['id']))
            @if ($explica)<p class="explica">{{ $explica['texto'] }}</p>@endif
            @if (! empty($p['restricciones']))<p class="restr"><b>Restricciones:</b> {{ implode(', ', $p['restricciones']) }}</p>@endif
        </div>
    @endforeach
</div>

<table class="rotulo" style="page-break-before: always; margin-top: 0"><tr><td><h2>La semana</h2></td><td class="aparte">Comida y cena elegidas · Individual por persona</td></tr></table>

@foreach ($d['dias'] as $dia)
    <table class="dia">
        <tr>
            <td class="fecha">
                <div class="azulejo">{{ $dia['inicial'] }}</div>
                <div class="dnombre">{{ $dia['nombre'] }}</div>
            </td>
            @foreach ($dia['tomas'] as $momento => $t)
                @php($o = $t['opcion'])
                <td class="toma {{ $momento }}">
                    <div class="cab">
                        {{ $t['etiqueta'] }}
                        @if ($o)
                            <span class="reloj">{{ $o['duracion_momento_min'] }} min @if (($o['duracion_previa_min'] ?? 0) > 0) · {{ $o['duracion_previa_min'] }} antes @endif</span>
                        @endif
                    </div>
                    @if (! $o)
                        <p class="nada">No se cocina.</p>
                    @else
                        <h3>{{ $o['titulo'] }}</h3>
                        <div>
                            @foreach ($o['categorias'] ?? [] as $i => $c)
                                <span class="chip {{ $i === 0 ? 'destaca' : '' }}">{{ CategoriaComida::tryFrom($c)?->getLabel() ?? $c }}</span>
                            @endforeach
                        </div>
                        @if (! empty($o['pasos']))
                            <ol class="pasos">
                                @foreach ($o['pasos'] as $paso)<li>{{ $paso }}</li>@endforeach
                            </ol>
                        @endif
                        @if (! empty($o['ingredientes']))
                            <p class="ing"><b>Con</b>
                                {{ collect($o['ingredientes'])->map(fn ($i) => $i['nombre'].(isset($i['cantidad']) ? ' '.$cantidad((float) $i['cantidad'], (string) ($i['unidad'] ?? '')) : ''))->implode(' · ') }}
                            </p>
                        @endif
                    @endif
                </td>
            @endforeach
        </tr>
        @if ($dia['individuales'])
            <tr>
                <td colspan="3" style="padding:0">
                    <table class="indiv" style="width:100%;border-collapse:collapse;table-layout:fixed">
                        @foreach ($dia['individuales'] as $ind)
                            <tr>
                                <td style="width:58pt"><span class="quien">{{ $ind['nombre'] }}</span></td>
                                @foreach ($ind['tomas'] as $m => $x)
                                    @php($tm = $x['toma'])
                                    <td>
                                        <span class="m">{{ $x['etiqueta'] }}</span>
                                        <span class="mins">{{ $tm['duracion_momento_min'] ?? 0 }} min @if (($tm['duracion_previa_min'] ?? 0) > 0) · {{ $tm['duracion_previa_min'] }} antes @endif</span><br>
                                        <span class="t">{{ $tm['titulo'] ?? '' }}</span>
                                        @if (! empty($tm['descripcion']))<div class="sub">{{ $tm['descripcion'] }}</div>@endif
                                        @if (! empty($tm['ingredientes']))
                                            <div class="sub">{{ collect($tm['ingredientes'])->map(fn ($i) => $i['nombre'].(isset($i['cantidad']) ? ' '.$cantidad((float) $i['cantidad'], (string) ($i['unidad'] ?? '')) : ''))->implode(' · ') }}</div>
                                        @endif
                                        @if (! empty($tm['nota']))<div class="nota">{{ $tm['nota'] }}</div>@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @endif
    </table>
@endforeach

<p class="pie">
    Menú generado con ayuda de un asistente de IA a partir de los datos del hogar (gustos, restricciones, despensa y lo comido).
    Las cantidades y los tiempos son orientativos. Esto no sustituye el consejo de un profesional sanitario: si hay una condición
    médica, un embarazo o un objetivo de peso concreto, que alguien lo revise con los datos delante.
    Generado el {{ $d['generado']->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}.
</p>

</body>
</html>
