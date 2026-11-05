@php
    $hash = (string) ($getRecord()?->foto_hash ?? '');
@endphp
@if (preg_match('/^[0-9a-f]{64}$/', $hash))
    <a href="{{ route('fotos', $hash) }}" target="_blank" rel="noopener">
        <img src="{{ route('fotos', $hash) }}" alt="Foto de la comida" class="max-h-64 rounded-lg" loading="lazy">
    </a>
@endif
