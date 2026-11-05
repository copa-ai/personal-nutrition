<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="Estado del chat">
        <ul class="list-disc pl-5 space-y-1">
            <li>Chat: <strong>{{ $chatHabilitado ? 'habilitado' : 'deshabilitado' }}</strong></li>
            <li>Conexión con Claude: <strong>{{ $tokenClaude ? 'configurada' : 'sin configurar' }}</strong>
                (<a class="underline" href="{{ \App\Filament\Pages\ConexionClaude::getUrl() }}">Conexión con Claude</a>)</li>
            <li>Responde: <strong>{{ $motor }}</strong>
                @unless ($tokenClaude) (sin la clave de Claude, el asistente la pedirá en el chat) @endunless</li>
            <li>Mensajes en la conversación: <strong>{{ $mensajes }}</strong></li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
