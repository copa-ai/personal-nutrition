@php
    $record = $getRecord();
    $archivo = $record->archivo_log;
    $lineas = [];
    if ($archivo && is_file($archivo)) {
        $tam = filesize($archivo);
        $f = fopen($archivo, 'rb');
        fseek($f, max(0, $tam - 60000));   // solo el final: una ejecucion larga genera un registro enorme
        $lineas = array_slice(explode("\n", rtrim((string) stream_get_contents($f))), -300);
        fclose($f);
    }
@endphp
@include('filament.menus._estilos')
@if ($lineas)
    <p class="mn mn-suave" style="font-size:.75rem;margin-bottom:.5rem">Últimas {{ count($lineas) }} líneas del registro del agente @if ($record->estado->enCurso()) · se actualiza sola mientras trabaja @endif</p>
    <pre style="max-height:32rem;overflow:auto;white-space:pre-wrap;word-break:break-word;border-radius:.5rem;background:#0b0f19;color:#e5e7eb;padding:.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;line-height:1.5;margin:0"
         x-data x-init="$el.scrollTop = $el.scrollHeight">{{ implode("\n", $lineas) }}</pre>
@else
    <div class="mn mn-vacio">
        <x-filament::icon icon="heroicon-o-command-line" class="mn-icono" />
        <p><strong>Todavía no hay registro.</strong></p>
        <p>Aquí verás, paso a paso, lo que hace el agente cuando se genera el menú.</p>
    </div>
@endif
