<x-filament-panels::page>
    @unless ($disponible)
        <x-filament::section>
            Las copias de seguridad solo están disponibles cuando la base de datos es SQLite.
        </x-filament::section>
    @endunless
    <x-filament::section heading="Qué incluye">
        <ul class="list-disc pl-5 space-y-1">
            <li>Toda la base de datos: hogares, perfiles, despensa, menús, lo comido y los usuarios del panel (con su contraseña cifrada).</li>
            <li><strong>No</strong> incluye la clave de conexión con Claude (se cifra con la clave de la aplicación): tras restaurar en un servicio nuevo, vuelve a pegarla en «Conexión con Claude».</li>
            <li><strong>No</strong> incluye las sesiones, la cola de trabajos ni la memoria interna del agente.</li>
        </ul>
    </x-filament::section>
    <x-filament::section heading="Cómo usarla">
        <ol class="list-decimal pl-5 space-y-1">
            <li>Pulsa «Descargar copia» y guarda el archivo <code>.sqlite</code> fuera del servicio.</li>
            <li>Si el servicio se pierde, arranca uno nuevo, entra con el usuario inicial y pulsa «Restaurar copia» (máximo 12 MB).</li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
