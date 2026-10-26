@php
    use App\Enums\EstadoMenu;
    use App\Filament\Support\PestanaConsumo;
    use App\Services\Menu\ResumenConsumo;
    use Carbon\CarbonImmutable;

    /** @var \App\Models\Menu $record */
    $record = $getRecord();
    $inicio = CarbonImmutable::parse($record->fecha_inicio)->locale('es');
    $fin = $inicio->addDays(max(0, (int) config('agente.menu.dias') - 1));
    $periodo = $inicio->month === $fin->month
        ? $inicio->day.' – '.$fin->isoFormat('D MMM YYYY')
        : $inicio->isoFormat('D MMM').' – '.$fin->isoFormat('D MMM YYYY');
    $coste = ResumenConsumo::de($record)['costes']['total'] ?? null;
    $conMenu = $record->contenido !== null;
    $enCurso = $record->estado->enCurso();
    $pct = $record->progreso();
@endphp
@include('filament.menus._estilos')
<div class="mn mn-resumen">

    @if ($record->estado === EstadoMenu::Fallido && filled($record->error))
        <div class="mn-alerta mn-alerta--error" role="alert">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mn-icono" style="color: var(--danger-500, #ef4444)" />
            <div>
                <p class="titulo">No se pudo generar el menú</p>
                <p>{{ $record->error }}</p>
                <p class="mn-suave" style="margin-top:.25rem">Pulsa «Generar menú» para volver a intentarlo.</p>
            </div>
        </div>
    @elseif ($record->estado === EstadoMenu::EnEspera)
        <div class="mn-alerta mn-alerta--aviso" role="status">
            <x-filament::icon icon="heroicon-o-clock" class="mn-icono" style="color: var(--info-500, #3b82f6)" />
            <div>
                <p class="titulo">Esperando a que se renueve el límite de uso de Claude</p>
                <p>@if ($record->proximo_ciclo_en) Se reintentará solo {{ $record->proximo_ciclo_en->diffForHumans() }}. @endif Puedes adelantarlo con «Reintentar ahora».</p>
            </div>
        </div>
    @endif

    <div class="mn-ident">
        <div>
            <span class="mn-etiqueta">Estado</span>
            <span><x-filament::badge :color="$record->estado->getColor()" :icon="$record->estado->getIcon()">{{ $record->etiquetaEstado() }}</x-filament::badge></span>
        </div>
        <div>
            <span class="mn-etiqueta">Hogar</span>
            <span class="valor">{{ $record->hogar->nombre }}</span>
        </div>
        <div>
            <span class="mn-etiqueta">Semana</span>
            <span class="valor">{{ $periodo }}</span>
        </div>
        @if ($record->iniciado_en && $record->terminado_en && $record->estado === EstadoMenu::Terminado)
            <div>
                <span class="mn-etiqueta">Generado en</span>
                <span class="valor">{{ PestanaConsumo::duracion((int) $record->iniciado_en->diffInSeconds($record->terminado_en)) }}</span>
            </div>
        @endif
    </div>

    @if ($enCurso)
        <div class="mn-progreso">
            <div style="display:flex;justify-content:space-between;gap:1rem">
                <strong>{{ $record->etiquetaProgreso() }}</strong>
                <span class="mn-suave">{{ $pct }} %</span>
            </div>
            <div class="barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="Avance de la generación del menú">
                <span style="width: {{ $pct }}%"></span>
            </div>
            <p class="mn-suave" style="font-size:.75rem">Estimación según la fase en la que va el agente. El detalle está en la pestaña Registro.</p>
        </div>
    @endif

    @if ($conMenu || ($coste !== null && $coste > 0))
        <div class="mn-kpis">
            @if ($conMenu)
                <div class="mn-kpi">
                    <span class="mn-etiqueta">Días</span>
                    <span class="valor">{{ $record->diasConMenu() }} de {{ config('agente.menu.dias') }}</span>
                    <span class="nota">{{ $record->totalTomas() }} tomas con receta</span>
                </div>
                <div class="mn-kpi">
                    <span class="mn-etiqueta">Opciones elegidas</span>
                    <span class="valor">{{ $record->totalElegidas() }} de {{ $record->totalTomas() }}</span>
                    <span class="nota">En las demás se usa la primera opción</span>
                </div>
                <div class="mn-kpi">
                    <span class="mn-etiqueta">Opciones de receta</span>
                    <span class="valor">{{ $record->totalOpciones() }}</span>
                    <span class="nota">Entre las que elegir</span>
                </div>
            @endif
            @if ($coste !== null && $coste > 0)
                <div class="mn-kpi">
                    <span class="mn-etiqueta">Coste estimado</span>
                    <span class="valor">{{ PestanaConsumo::dinero($coste) }}</span>
                    <span class="nota">Detalle en la pestaña Consumo</span>
                </div>
            @endif
        </div>
    @endif
</div>
