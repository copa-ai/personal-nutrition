@php
    $record = $getRecord();
@endphp
<div class="flex flex-wrap gap-4">
    @foreach ($record->imagenes() as $imagen)
        @if (preg_match('/^[0-9a-f]{64}$/', (string) ($imagen['hash'] ?? '')))
            <a href="{{ route('fotos', $imagen['hash']) }}" target="_blank" rel="noopener">
                <img src="{{ route('fotos', $imagen['hash']) }}" alt="{{ $imagen['alt'] ?? ($imagen['nombre'] ?? 'Foto') }}"
                     class="max-h-64 rounded-lg" loading="lazy">
            </a>
        @endif
    @endforeach
</div>
