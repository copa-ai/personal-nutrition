@php
    /** @var \App\Models\Menu $record */
    $record = $getRecord();
    $lista = $record->listaCompra();
    $cantidad = fn ($n, string $unidad) => $n === null ? ($unidad !== '' ? $unidad : '—') : rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',').($unidad !== '' ? ' '.$unidad : '');
@endphp
@include('filament.menus._estilos')

@if ($record->contenido === null)
    <div class="mn mn-vacio">
        <x-filament::icon icon="heroicon-o-shopping-cart" class="mn-icono" />
        <p><strong>La lista de la compra sale del menú.</strong></p>
        <p>Cuando haya un menú generado, aquí verás lo que hay que comprar para las opciones que elijas.</p>
    </div>
@else
    <div class="mn mn-menu">
        <div class="mn-ayuda">
            <span><strong>{{ count($lista['comprar']) }}</strong> {{ count($lista['comprar']) === 1 ? 'artículo' : 'artículos' }} por comprar · <strong>{{ count($lista['en_casa']) }}</strong> ya en casa</span>
            <span class="mn-suave">Calculada con la opción elegida en cada toma. Cambia si cambias una elección.</span>
        </div>

        <div class="mn-bloque">
            <span class="mn-etiqueta">Hay que comprar</span>
            @if ($lista['comprar'])
                <ul class="mn-compra-lista">
                    @foreach ($lista['comprar'] as $i)
                        <li><span>{{ $i['nombre'] }}</span><span class="cant">{{ $cantidad($i['cantidad'], $i['unidad']) }}</span></li>
                    @endforeach
                </ul>
            @else
                <p class="mn-suave">No hace falta comprar nada: todo lo elegido está ya en casa.</p>
            @endif
        </div>

        @if ($lista['en_casa'])
            <details class="mn-detalles">
                <summary>Ya en casa ({{ count($lista['en_casa']) }}): no hace falta comprarlo</summary>
                <ul class="mn-compra-lista mn-compra-lista--casa" style="margin-top:.5rem">
                    @foreach ($lista['en_casa'] as $i)
                        <li><span>{{ $i['nombre'] }}</span><span class="cant">{{ $cantidad($i['cantidad'], $i['unidad']) }}</span></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endif
